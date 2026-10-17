<?php
/**
 * Storage root check (src/Setup.php): a missing root or one inside the web
 * root is refused, and what a visitor and the operator see then. In-process
 * with real Kirby.
 *   php tests/SetupTest.php
 */
$base = dirname(__DIR__);
if (!is_file($base . '/vendor/autoload.php')) { fwrite(STDERR, "Composer dependencies missing; run composer install first\n"); exit(1); }
require $base . '/vendor/autoload.php';
require_once $base . '/src/Setup.php';

use Kizami\Setup;
use Kizami\SetupError;

$logFile = sys_get_temp_dir() . '/kizami-setup-log-' . bin2hex(random_bytes(6)) . '.txt';
ini_set('error_log', $logFile);
register_shutdown_function(fn () => @unlink($logFile));

$n = 0;
function same($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}
function refused(?string $storage, ?string $index): ?string
{
    try { Setup::storageRoot($storage, $index); return null; } catch (SetupError $e) { return $e->getMessage(); }
}

$tmp = sys_get_temp_dir() . '/kizami-setup-' . bin2hex(random_bytes(6));
$failed = false;
try {
    foreach (['public/a&b', 'public2', 'storage'] as $dir) { mkdir($tmp . '/' . $dir, 0700, true); }
    $tmp = realpath($tmp);
    $public = $tmp . '/public';

    // Unit: the rule itself.
    same('Kizami: Kirby root "storage" is not set. Define it in index.php outside the web root (see README, Installation).', refused(null, $public), 'No storage root');
    same(true, refused('', $public) !== null && refused('  ', $public) !== null, 'Empty storage root');
    same(null, refused($tmp . '/storage', $public), 'Sibling of the web root is fine');
    same(null, refused($tmp . '/storage/', $public . '/'), 'Trailing slashes do not matter');
    same(null, refused($tmp, $public), 'A parent of the web root is outside it');
    same(null, refused($tmp . '/public2', $public), 'A directory with the same name prefix is not inside');
    same(null, refused($tmp . '/not/yet/there', $public), 'Missing storage directory outside the web root');
    same(
        'Kizami: storage root ' . $public . '/storage lies inside the web root ' . $public . '. Move it outside; a web server would serve the database and the daily key as files.',
        refused($public . '/storage', $public), 'Missing directory inside the web root'
    );
    same(true, refused($public, $public) !== null, 'Equal to the web root counts as inside');
    same(true, refused($public . '/a&b', $public) !== null, 'Existing directory inside the web root');
    same(true, refused($public . '/x/../a&b', $public) !== null, 'Dot segments of an existing path are resolved');
    same(true, refused($public . '/a/b/c', $public . '/') !== null, 'Deeply missing path inside, web root with a trailing slash');
    same(null, refused($tmp . '/storage', null), 'No index root: nothing to compare');

    // Symlinks: a document root and a storage root can both be links.
    symlink($public, $tmp . '/pub-link');
    symlink($tmp . '/storage', $tmp . '/storage-link');
    same(true, refused($tmp . '/pub-link/storage', $public) !== null, 'Storage inside a symlink to the web root');
    same(true, refused($public . '/data', $tmp . '/pub-link') !== null, 'Web root given as a symlink');
    same(null, refused($tmp . '/storage-link', $tmp . '/pub-link'), 'Both links, outside');
    symlink($public, $tmp . '/storage/in-public');
    same(true, refused($tmp . '/storage/in-public/x', $public) !== null, 'Link below the storage root that leads into the web root');

    // Real Kirby.
    foreach (['site/plugins', 'site/config', 'site/templates', 'content/home', 'content/error'] as $dir) { mkdir($tmp . '/' . $dir, 0700, true); }
    symlink($base, $tmp . '/site/plugins/kizami');
    file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
    file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
    file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
    file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
    file_put_contents($tmp . '/site/config/kizami.php', '<?php return ' . var_export([
        'user' => 'client', 'hash' => password_hash('client-password', PASSWORD_DEFAULT),
    ], true) . ';');

    $app = function (?string $storage, string $url, array $request = [], array $extra = []) use ($tmp) {
        $roots = ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site'];
        if ($storage !== null) { $roots['storage'] = $storage; }
        return new Kirby\Cms\App([
            'roots' => $roots,
            'request' => ['method' => 'GET', 'url' => $url] + $request,
            'options' => [
                'url' => 'https://example.invalid', 'kizami.active' => true,
                'kizami.redirects' => ['call' => 'tel:+4912345'],
            ] + $extra,
        ]);
    };
    $visitor = fn () => $_SERVER = array_merge($_SERVER, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0', 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_SEC_FETCH_USER' => '?1']);
    $login = function () { $_SERVER['PHP_AUTH_USER'] = 'client'; $_SERVER['PHP_AUTH_PW'] = 'client-password'; };
    $visitor();

    $cases = [
        'inside the web root' => [$tmp . '/public/a&b/storage', 'storage root ' . $tmp . '/public/a&b/storage lies inside the web root'],
        'missing' => [null, 'Kirby root "storage" is not set'],
    ];
    foreach ($cases as $label => [$storage, $message]) {
        file_put_contents($logFile, '');
        $kirby = $app($storage, 'https://example.invalid/');
        $body = (string) $kirby->render('/')->body();
        same('Fixture', $body, "Page renders ($label)");
        same(true, str_contains((string) file_get_contents($logFile), 'Kizami: ' . $message), "Error log names the cause ($label)");
        same([], glob($tmp . '/public/a&b/storage/*') ?: [], "Nothing written below the web root ($label)");
        same([], glob($tmp . '/public/*.sqlite') ?: [], "No database in the web root ($label)");

        $response = $app($storage, 'https://example.invalid/call?from=%2F')->call('call', 'GET');
        same([302, 'tel:+4912345'], [$response->code(), $response->headers()['Location'] ?? null], "Redirect still redirects ($label)");

        // The file login needs the storage directory (lockout counters), so
        // without a usable root it is denied without checking the password:
        // 503 with a pointer to the error log, no challenge, no path. The
        // Panel login does not need it and shows the cause.
        $login();
        file_put_contents($logFile, '');
        $response = $app($storage, 'https://example.invalid/k/dashboard')->call('k/dashboard', 'GET');
        same(503, $response->code(), "File login without a usable root: 503 ($label)");
        same('Kizami: setup error, see the error log.', $response->body(), "...with a pointer and no path ($label)");
        same(false, isset($response->headers()['WWW-Authenticate']), "...and no challenge ($label)");
        same(true, str_contains((string) file_get_contents($logFile), 'Kizami: ' . $message), "...the cause is logged ($label)");
        // In preview mode the module hides behind Kirby's 404.
        $response = $app($storage, 'https://example.invalid/k/dashboard', [], ['kizami.previewAccess' => ['user' => 'client', 'hash' => password_hash('client-password', PASSWORD_DEFAULT)]])->call('k/dashboard', 'GET');
        same(404, $response->code(), "Preview mode with a setup error: real 404 ($label)");
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);

        $kirby = $app($storage, 'https://example.invalid/k/dashboard');
        $kirby->impersonate('kirby');
        $response = $kirby->call('k/dashboard', 'GET');
        same(503, $response->code(), "Dashboard for the Panel user answers 503 ($label)");
        $html = $response->body();
        same(true, str_contains($html, htmlspecialchars('Kizami: ' . $message, ENT_QUOTES)), "Dashboard shows the setup message, escaped ($label)");
        same(true, str_contains($html, 'href="/k/dashboard.css"'), "503 page keeps the stylesheet ($label)");
        same(false, str_contains($html, 'a&b'), "No raw ampersand in the 503 page ($label)");
    }

    // A good root counts.
    $kirby = $app($tmp . '/storage', 'https://example.invalid/');
    same('Fixture', (string) $kirby->render('/')->body(), 'Page renders with a good root');
    same(true, is_file($tmp . '/storage/kizami/kizami.sqlite'), 'A good root: the database is written');
    $login();
    $response = $app($tmp . '/storage', 'https://example.invalid/k/dashboard')->call('k/dashboard', 'GET');
    same([200, false], [$response->code(), str_contains($response->body(), 'class="warning"')], 'A good root: dashboard 200 without warning');
} catch (Throwable $e) {
    fwrite(STDERR, 'Setup: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    if (is_dir($tmp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($tmp);
    }
}
if ($failed) { exit(1); }
echo "Setup: all $n checks passed\n";
