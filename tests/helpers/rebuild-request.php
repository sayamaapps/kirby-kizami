<?php
/**
 * Child process of tests/RebuildTest.php, not a test itself (PHPUnit only runs
 * the *Test.php files). One request the way Runtime::write() handles it, in a
 * PHP process of its own, so the rebuild really runs from the shutdown
 * functions after the script:
 *
 *   php tests/helpers/rebuild-request.php '<JSON options>'
 *
 * Options: db, journal, now (UTC, the request time), state (file the probes
 * append to), log (error log), and
 * - kind: `count` (writes a page view, then Rebuild::afterCount()), `bot`
 *   (Rebuild::afterBot()), `bot-throws` (the Store cannot be opened),
 *   `rebuild` (Store::rebuildDaily() at once, prints true/false; waits for
 *   the file `go` first) or `writer` (page views every 5 ms until the file
 *   `stop` exists, prints {"ok":n,"errors":[…]}).
 * - fpm: defines fastcgi_finish_request() as PHP-FPM has it. The CLI has
 *   none, like cgi-fcgi.
 * - lock: holds the write lock from another connection until the process ends.
 *
 * The page is the line `PAGE`. A probe appends the rebuild state of the file
 * (claimed day, free pages, user_version) to the state file: at the end of the
 * script and, with fpm, when the response is ended (printed as `FINISH`).
 */
$base = dirname(__DIR__, 2);
$classes = require $base . '/autoload.php';
spl_autoload_register(function (string $class) use ($classes, $base): void {
	if (isset($classes[strtolower($class)])) {
		require_once $base . '/' . $classes[strtolower($class)];
	}
});

use Sayamaapps\Kizami\Rebuild;
use Sayamaapps\Kizami\Store;

$options = json_decode($argv[1] ?? '', true);
if (!is_array($options)) {
	fwrite(STDERR, "usage: rebuild-request.php '<JSON options>'\n");
	exit(2);
}
ini_set('error_log', $options['log']);
ini_set('log_errors', '1');
$now = new DateTimeImmutable($options['now'], new DateTimeZone('UTC'));

$GLOBALS['probe'] = function (string $when) use ($options): void {
	$db = new SQLite3($options['db']);
	$db->busyTimeout(5000);
	file_put_contents($options['state'], json_encode([
		'when' => $when,
		'day' => (string)$db->querySingle('SELECT day FROM kizami_rebuild WHERE id=1'),
		'free' => (int)$db->querySingle('PRAGMA freelist_count'),
		'version' => (int)$db->querySingle('PRAGMA user_version'),
	]) . "\n", FILE_APPEND);
	$db->close();
};

if (!empty($options['fpm'])) {
	// PHP-FPM ends the response here; the probe shows what the file looked
	// like at that moment.
	function fastcgi_finish_request(): bool
	{
		echo "FINISH\n";
		$GLOBALS['probe']('finish');
		return true;
	}
}

switch ($options['kind']) {
	case 'writer':
		$store = new Store($options['db'], $options['journal']);
		$ok = 0;
		$errors = [];
		touch(dirname($options['db']) . '/writer-ready');
		while (!is_file(dirname($options['db']) . '/stop')) {
			try {
				$store->write(['type' => 'page', 'action' => 'view', 'path' => '/parallel', 'session' => 'w' . $ok, 'created_at' => gmdate('Y-m-d H:i:s')]);
				$ok++;
			} catch (Throwable $e) {
				$errors[] = $e->getMessage();
			}
			usleep(5000);
		}
		echo json_encode(['ok' => $ok, 'errors' => $errors]), "\n";
		exit;
	case 'rebuild':
		$store = new Store($options['db'], $options['journal']);
		while (!is_file(dirname($options['db']) . '/go')) {
			usleep(1000);
		}
		echo json_encode($store->rebuildDaily($now)), "\n";
		exit;
	case 'count':
		$store = new Store($options['db'], $options['journal']);
		$store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'visitor', 'created_at' => $now->format('Y-m-d H:i:s')]);
		Rebuild::afterCount($store, $now);
		break;
	case 'bot':
		Rebuild::afterBot(fn (): Store => new Store($options['db'], $options['journal']), $now);
		break;
	case 'bot-throws':
		Rebuild::afterBot(fn (): Store => throw new RuntimeException('Kizami test: the database cannot be opened'), $now);
		break;
}

if (!empty($options['lock'])) {
	// Another request that holds the write lock (kept until the process ends).
	$lock = new SQLite3($options['db']);
	$lock->exec('BEGIN IMMEDIATE');
	$lock->exec("INSERT INTO kizami_discarded (day, hits) VALUES ('2000-01-01', 1)");
}

echo "PAGE\n";
$GLOBALS['probe']('end of script');
