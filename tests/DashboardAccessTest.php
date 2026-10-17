<?php
/**
 * Dashboard access with real Kirby, in-process: HTTPS only (same option as
 * report and snapshot) and the failed-login limit with Kirby's own options.
 *   php tests/DashboardAccessTest.php
 */
$base = dirname(__DIR__);
if (!is_file($base . '/vendor/autoload.php')) { fwrite(STDERR, "Composer dependencies missing; run composer install first\n"); exit(1); }
require $base . '/vendor/autoload.php';

$logFile = sys_get_temp_dir() . '/kizami-access-log-' . bin2hex(random_bytes(6)) . '.txt';
ini_set('error_log', $logFile);
register_shutdown_function(fn () => @unlink($logFile));

$n = 0;
function same($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}

$tmp = sys_get_temp_dir() . '/kizami-access-' . bin2hex(random_bytes(6));
$failed = false;
try {
    foreach (['public', 'site/plugins', 'site/config', 'site/templates', 'content/home', 'content/error', 'storage'] as $dir) {
        mkdir($tmp . '/' . $dir, 0700, true);
    }
    symlink($base, $tmp . '/site/plugins/kizami');
    file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
    file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
    file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
    file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
    file_put_contents($tmp . '/site/config/kizami.php', '<?php return ' . var_export([
        'user' => 'client', 'hash' => password_hash('client-password', PASSWORD_DEFAULT),
    ], true) . ';');

    // The route as Kirby calls it; the login comes from $_SERVER like under PHP-FPM.
    $get = function (string $path, string $url, ?string $password = 'client-password', array $options = []) use ($tmp) {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        if ($password !== null) { $_SERVER['PHP_AUTH_USER'] = 'client'; $_SERVER['PHP_AUTH_PW'] = $password; }
        $kirby = new Kirby\Cms\App([
            'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
                'site' => $tmp . '/site', 'storage' => $tmp . '/storage'],
            'request' => ['method' => 'GET', 'url' => $url],
            'options' => array_merge(['url' => $url, 'kizami.active' => true], $options),
        ]);
        $response = $kirby->call($path, 'GET');
        return [$response->code(), $response->body(), $response->headers()];
    };

    foreach (['k/dashboard', 'k/dashboard.css'] as $route) {
        $https = "https://example.invalid/$route";
        $http = "http://example.invalid/$route";
        same(200, $get($route, $https)[0], "$route over HTTPS with the right login");
        [$code, $body, $headers] = $get($route, $http);
        same([403, 'HTTPS only.', false], [$code, $body, isset($headers['WWW-Authenticate'])], "$route over HTTP with the right login: refused, no challenge");
        same('no-store', $headers['Cache-Control'] ?? null, "$route refusal is not cached");
        [$code, , $headers] = $get($route, $http, null);
        same([403, false], [$code, isset($headers['WWW-Authenticate'])], "$route over HTTP without login: 403, nothing to prompt");
        same(200, $get($route, $http, options: ['kizami.reportWithoutHttps' => true])[0], "$route over HTTP with kizami.reportWithoutHttps");
        same(403, $get($route, $http, options: ['kizami.reportWithoutHttps' => 'yes'])[0], "$route: only a true switch opens HTTP");
        same(401, $get($route, $https, null)[0], "$route over HTTPS without login: the usual challenge");
        same(404, $get($route, $http, options: ['kizami.active' => false])[0], "$route with the module off: real 404 over HTTP too");
    }
    same(false, is_file($tmp . '/storage/kizami/logins.json'), 'Refused over HTTP: nothing counted');

    // Kirby's own options set the limit.
    $url = 'https://example.invalid/k/dashboard';
    $limit = ['auth.trials' => 2, 'auth.timeout' => 600];
    same(401, $get('k/dashboard', $url, 'wrong', $limit)[0], 'auth.trials: failure 1');
    same(401, $get('k/dashboard', $url, 'wrong', $limit)[0], 'auth.trials: failure 2');
    [$code, $body, $headers] = $get('k/dashboard', $url, 'client-password', $limit);
    same([429, 'Too many failed logins. Try again later.', false], [$code, $body, isset($headers['WWW-Authenticate'])], 'auth.trials 2: blocked on the third attempt');
    $wait = (int) ($headers['Retry-After'] ?? 0);
    same(true, $wait > 590 && $wait <= 600, 'auth.timeout gives Retry-After');
    same(200, $get('k/dashboard', $url, 'client-password', ['auth.trials' => 3] + $limit)[0], 'A higher limit lets the same address in again (counter-check)');
    unlink($tmp . '/storage/kizami/logins.json');

    // Kirby 404 stays a 404 where the module hides: preview without credentials.
    $preview = $limit + ['kizami.previewAccess' => ['user' => 'preview', 'hash' => password_hash('preview-password', PASSWORD_DEFAULT)]];
    same(404, $get('k/dashboard', $url, 'wrong', $preview)[0], 'Preview: wrong login 1');
    same(404, $get('k/dashboard', $url, 'wrong', $preview)[0], 'Preview: wrong login 2');
    same(404, $get('k/dashboard', $url, 'preview-password', $preview)[0], 'Preview, blocked: still Kirby\'s 404, not a 429 that reveals the module');
    unlink($tmp . '/storage/kizami/logins.json');

    // Unusable lockout file: deny, log, never let in.
    file_put_contents($tmp . '/storage/kizami/logins.json', 'garbage');
    same(401, $get('k/dashboard', $url)[0], 'Garbage lockout file: no access with the right login');
    same(true, str_contains((string) file_get_contents($GLOBALS['logFile']), 'Kizami: login lockout file not usable'), 'Garbage lockout file is logged');
    unlink($tmp . '/storage/kizami/logins.json');
    // A file where the storage directory should be: same.
    foreach (glob($tmp . '/storage/kizami/*') ?: [] as $f) { unlink($f); }
    rmdir($tmp . '/storage/kizami');
    file_put_contents($tmp . '/storage/kizami', 'x');
    file_put_contents($GLOBALS['logFile'], '');
    same(401, $get('k/dashboard', $url, 'wrong')[0], 'Storage directory unusable: a failure is denied as usual');
    same(true, str_contains((string) file_get_contents($GLOBALS['logFile']), 'Kizami: login lockout file not usable'), 'Uncountable failure is logged');
    unlink($tmp . '/storage/kizami');
    same(200, $get('k/dashboard', $url)[0], 'Storage usable again: access (counter-check)');
} catch (Throwable $e) {
    fwrite(STDERR, 'DashboardAccess: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    if (is_dir($tmp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($tmp);
    }
}
if ($failed) { exit(1); }
echo "DashboardAccess: all $n checks passed\n";
