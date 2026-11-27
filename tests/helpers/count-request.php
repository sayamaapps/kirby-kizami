<?php
/**
 * Child process of tests/RebuildTest.php, not a test itself (PHPUnit only runs
 * the *Test.php files). One counted page view through Runtime::write(), with
 * Kirby, in a PHP process of its own:
 *
 *   php tests/helpers/count-request.php '<JSON options>'
 *
 * or through PHP-FPM, with the options in a file (KIZAMI_TEST_OPTIONS).
 *
 * Options: root (Kirby root with public/ and storage/), log (error log),
 * finished (file that receives the time the response ended, with fpm),
 * journal (`wal` or `delete`), path (of the page view), ip (REMOTE_ADDR, so
 * another day identifier), click: a link click with the redirects' link
 * filter instead of a page view, `true` with a confirmed user action
 * (Sec-Fetch-User), `false` without. fpm: defines fastcgi_finish_request()
 * as PHP-FPM has it (every output buffer is ended and sent, then the response
 * is over). The CLI has none, like cgi-fcgi.
 *
 * Prints `PAGE` (the page) and, with fpm, `FINISH` when the response ends.
 * Without fpm the response ends with the process.
 */
$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';
Kirby\Filesystem\F::loadClasses(require $base . '/autoload.php', $base);
Kirby\Cms\App::$enableWhoops = false;

// Under PHP-FPM the options come as a file named in KIZAMI_TEST_OPTIONS.
$options = json_decode($argv[1] ?? (string)@file_get_contents((string)($_SERVER['KIZAMI_TEST_OPTIONS'] ?? '')), true);
if (!is_array($options)) {
	fwrite(STDERR, "usage: count-request.php '<JSON options>'\n");
	exit(2);
}
ini_set('error_log', $options['log']);
ini_set('log_errors', '1');
$GLOBALS['finished'] = $options['finished'];

if (!empty($options['fpm'])) {
	function fastcgi_finish_request(): bool
	{
		while (ob_get_level() > 0) {
			ob_end_flush();
		}
		echo "FINISH\n";
		file_put_contents($GLOBALS['finished'], (string)microtime(true));
		return true;
	}
}

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';
$_SERVER['REMOTE_ADDR'] = $options['ip'] ?? '198.51.100.7';
$root = $options['root'];
$kirby = new Kirby\Cms\App([
	'roots' => ['index' => $root . '/public', 'base' => $root, 'content' => $root . '/content', 'site' => $root . '/site', 'storage' => $root . '/storage'],
	'options' => ['url' => 'https://example.org', 'sayamaapps.kizami.active' => true, 'sayamaapps.kizami.journal' => $options['journal'] ?? 'wal'],
]);
if (isset($options['click'])) {
	// What the redirect route writes (Routes::redirect()).
	Sayamaapps\Kizami\Runtime::write($kirby, ['type' => 'redirect', 'action' => 'click', 'path' => $options['path'] ?? '/', 'target' => 'call'], new Sayamaapps\Kizami\LinkFilter($options['click'] === true));
} else {
	Sayamaapps\Kizami\Runtime::write($kirby, ['type' => 'page', 'action' => 'view', 'path' => $options['path'] ?? '/']);
}
echo "PAGE\n";
