<?php

/**
 * The rebuild of the database file after the response (Rebuild, since
 * 3.1.0): at most once per day, only after the response, without losing a
 * parallel count, and a failure never reaches the page. Requests run in child
 * processes (tests/helpers/rebuild-request.php), so the shutdown functions
 * really run after the script. Disposable SQLite fixtures only.
 *   vendor/bin/phpunit tests/RebuildTest.php
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Analysis;
use Sayamaapps\Kizami\I18n;
use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Rebuild;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Analysis::class)]
#[CoversClass(\Sayamaapps\Kizami\Rebuild::class)]
#[CoversClass(\Sayamaapps\Kizami\Runtime::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class RebuildTest extends TestCase
{
	/** A request time: Thursday noon in Berlin. */
	private const NOW = '2026-11-12 11:00:00';

	private string $root = '';

	/** UTC time relative to NOW, as kizami_rebuild.done stores it. */
	private static function before(string $modifier): string
	{
		return (new DateTimeImmutable(self::NOW . ' ' . $modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	}

	/**
	 * A database with free pages, so a rebuild is visible: afterwards
	 * freelist_count is 0. $done/$day preset the rebuild state ('' = none).
	 */
	private function database(string $name, string $journal, int $rows = 3000, string $done = '', string $day = ''): string
	{
		mkdir($this->root . '/' . $name, 0700);
		$file = $this->root . '/' . $name . '/' . Store::FILE;
		$store = new Store($file, $journal);
		unset($store);
		$db = new SQLite3($file);
		$db->busyTimeout(5000);
		$db->exec('BEGIN');
		$stmt = $db->prepare("INSERT INTO kizami_events (created_at, type, action, path, session, device) VALUES (:at, 'page', 'view', :path, :session, 'desktop')");
		for ($i = 0; $i < $rows; $i++) {
			$stmt->bindValue(':at', '2026-11-0' . (1 + $i % 9) . ' 10:00:00', SQLITE3_TEXT);
			$stmt->bindValue(':path', '/page-' . ($i % 50), SQLITE3_TEXT);
			$stmt->bindValue(':session', hash('sha256', (string)intdiv($i, 3)), SQLITE3_TEXT);
			$stmt->execute()->finalize();
		}
		$db->exec('COMMIT');
		$db->exec('DELETE FROM kizami_events WHERE id % 3 <> 0');
		if ($done !== '' || $day !== '') {
			$stmt = $db->prepare('INSERT INTO kizami_rebuild (id, day, done) VALUES (1, :day, :done)');
			$stmt->bindValue(':day', $day, SQLITE3_TEXT);
			$stmt->bindValue(':done', $done, SQLITE3_TEXT);
			$stmt->execute()->finalize();
		}
		$db->close();
		Assert::assertGreaterThan(0, self::scalar($file, 'PRAGMA freelist_count'), "$name: counter-check, the fixture has free pages");
		return $file;
	}

	/**
	 * A minimal FastCGI client: one request, returns the STDOUT stream and
	 * whether END_REQUEST came within $timeout seconds.
	 *
	 * @return array{stdout:string,ended:bool}
	 */
	private static function fastCgi(string $socket, array $params, float $timeout): array
	{
		$record = fn (int $type, string $content): string => pack('CCnnCx', 1, $type, 1, strlen($content), 0) . $content;
		$pairs = '';
		foreach ($params as $name => $value) {
			foreach ([strlen($name), strlen($value)] as $length) {
				$pairs .= $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
			}
			$pairs .= $name . $value;
		}
		$connection = stream_socket_client('unix://' . $socket, $errno, $error, 5);
		Assert::assertIsResource($connection, "FastCGI connection: $error");
		fwrite($connection, $record(1, pack('nCx5', 1, 0)) . $record(4, $pairs) . $record(4, '') . $record(5, ''));
		stream_set_timeout($connection, (int)ceil($timeout));
		$stdout = '';
		$ended = false;
		$start = microtime(true);
		while (!$ended && microtime(true) - $start < $timeout) {
			$header = fread($connection, 8);
			if ($header === false || strlen($header) < 8) {
				break;
			}
			['type' => $type, 'length' => $length, 'padding' => $padding] = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $header);
			$content = '';
			while (strlen($content) < $length + $padding) {
				$content .= (string)fread($connection, $length + $padding - strlen($content));
			}
			if ($type === 6) {
				$stdout .= substr($content, 0, $length);
			}
			$ended = $type === 3;
		}
		fclose($connection);
		return ['stdout' => $stdout, 'ended' => $ended];
	}

	/**
	 * A Kirby root for tests/helpers/count-request.php with an empty database
	 * whose rebuild of today has run, so a request only counts.
	 *
	 * @return array{0:string,1:string} root, database file
	 */
	private function kirbyRoot(string $name): array
	{
		$root = $this->root . '/' . $name;
		foreach (['public', 'content', 'site', 'storage/kizami'] as $dir) {
			mkdir("$root/$dir", 0700, true);
		}
		$file = "$root/storage/kizami/" . Store::FILE;
		$store = new Store($file, 'wal');
		unset($store);
		$db = new SQLite3($file);
		$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
		$db->exec("INSERT INTO kizami_rebuild (id, day, done) VALUES (1, '$today', '" . gmdate('Y-m-d H:i:s') . "')");
		$db->close();
		return [$root, $file];
	}

	/**
	 * One request in a child process. Returns its output, exit code, error
	 * log and the probes (state of the file at the end of the script and,
	 * with fpm, when the response was ended).
	 *
	 * @return array{out:string,code:int,log:string,probes:array<string,array{day:string,free:int,version:int}>}
	 */
	private function request(string $file, array $options): array
	{
		$options += ['db' => $file, 'journal' => 'wal', 'now' => self::NOW, 'state' => $file . '.state', 'log' => $file . '.log'];
		['state' => $state, 'log' => $log] = $options;
		@unlink($state);
		@unlink($log);
		$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/rebuild-request.php', json_encode($options)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		$code = proc_close($process);
		Assert::assertSame('', $err, 'child process writes nothing to stderr');
		$probes = [];
		foreach (file(is_file($state) ? $state : '/dev/null', FILE_IGNORE_NEW_LINES) as $line) {
			$probe = json_decode($line, true);
			$probes[$probe['when']] = $probe;
		}
		return ['out' => (string)$out, 'code' => $code, 'log' => (string)@file_get_contents($log), 'probes' => $probes];
	}

	private static function scalar(string $file, string $sql): mixed
	{
		$db = new SQLite3($file);
		$db->busyTimeout(5000);
		$value = $db->querySingle($sql);
		$db->close();
		return $value;
	}

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/kizami-rebuild-' . bin2hex(random_bytes(8));
		mkdir($this->root, 0700);
	}

	protected function tearDown(): void
	{
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->root);
	}

	/**
	 * Without a way to end the response (cgi-fcgi, mod_php) the rebuild first
	 * sends what output buffers hold: the bot gets its whole page while the
	 * rebuild still waits for the write lock.
	 */
	public function testBufferedPageIsSentBeforeTheRebuild(): void
	{
		$file = $this->database('flush', 'wal');
		$lock = new SQLite3($file);
		$lock->exec('BEGIN IMMEDIATE');
		$lock->exec("INSERT INTO kizami_discarded (day, hits) VALUES ('2000-01-01', 1)");
		$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/rebuild-request.php', json_encode([
			'kind' => 'bot', 'buffer' => true, 'db' => $file, 'journal' => 'wal', 'now' => self::NOW, 'state' => "$file.state", 'log' => "$file.log",
		])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		stream_set_blocking($pipes[1], false);
		$out = '';
		$start = microtime(true);
		while (!str_contains($out, "PAGE\n") && microtime(true) - $start < 3) {
			$out .= (string)fread($pipes[1], 8192);
			usleep(20000);
		}
		$seenWhileLocked = $out;
		$lock->exec('ROLLBACK');
		$lock->close();
		stream_set_blocking($pipes[1], true);
		$out .= (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		proc_close($process);
		Assert::assertSame("PAGE\n", $seenWhileLocked, 'the buffered page arrived while the rebuild still waited');
		Assert::assertSame("PAGE\n", $out, 'nothing else on the page');
		Assert::assertSame('', $err, 'no stderr');
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'rebuilt once the lock was free');
	}

	public function testCountedVisitRebuildsAfterFortyEightHours(): void
	{
		Assert::assertSame(48, Rebuild::OVERDUE_HOURS, 'the privacy text promises two days');
		$now = new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
		Assert::assertTrue(Rebuild::overdue('', $now), 'never rebuilt: overdue');
		Assert::assertFalse(Rebuild::overdue(self::before('-48 hours'), $now), 'exactly 48 hours: not yet');
		Assert::assertTrue(Rebuild::overdue(self::before('-48 hours -1 second'), $now), 'one second more: overdue');
		Assert::assertTrue(Rebuild::overdue('not a time', $now), 'unreadable: overdue');

		// No way to end the response: 47 hours after the last rebuild a counted
		// visit does not rebuild, 49 hours after it does, at the end of its request.
		$file = $this->database('47h', 'wal', 3000, self::before('-47 hours'), '2026-11-11');
		$this->request($file, ['kind' => 'count']);
		Assert::assertGreaterThan(0, self::scalar($file, 'PRAGMA freelist_count'), '47 hours: no rebuild');
		$file = $this->database('49h', 'wal', 3000, self::before('-49 hours'), '2026-11-11');
		$result = $this->request($file, ['kind' => 'count']);
		Assert::assertSame("PAGE\n", $result['out'], '49 hours: the whole page');
		Assert::assertGreaterThan(0, $result['probes']['end of script']['free'] ?? 0, '49 hours: nothing rebuilt while the page is rendered');
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), '49 hours: the counted visit rebuilds at its end');
		Assert::assertSame(['2026-11-12', self::NOW], [self::scalar($file, 'SELECT day FROM kizami_rebuild'), self::scalar($file, 'SELECT done FROM kizami_rebuild')], '49 hours: day claimed, success recorded');

	}

	/**
	 * The four texts (README, PRIVACY, CHANGELOG, DATENSCHUTZ) say the same:
	 * the promise and the rest case in the same words, with the numbers the
	 * code uses.
	 */
	public function testDocsSayTheSame(): void
	{
		Assert::assertSame([48, 60, 5000], [Rebuild::OVERDUE_HOURS, Rebuild::WRITE_WAIT_SECONDS, Store::BUSY_TIMEOUT_MS], 'the numbers the texts name');
		$read = fn (string $file): string => preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__) . '/' . $file));
		$english = [
			'promise' => 'Leftover bytes of deleted content in free pages of the database file, including those left by older versions, are removed when the file is rebuilt: as a rule daily, after a page has been delivered, at the latest at the first counted visit once the last successful rebuild is more than two days old, provided the rebuild succeeds; if it keeps failing, the dashboard shows a warning.',
			'rest' => 'Where the response can be ended first, a hit (page view, click, action) is written after the response and waits up to 60 seconds for a running rebuild. Without `fastcgi_finish_request()` or `litespeed_finish_request()` (cgi-fcgi, mod_php) it is written during the request and waits at most 5 seconds, as before; if a rebuild takes longer, that hit is not counted (error log: `Kizami: database is locked`).',
		];
		foreach (['README.md', 'PRIVACY.md', 'CHANGELOG.md'] as $file) {
			foreach ($english as $what => $sentence) {
				Assert::assertStringContainsString($sentence, $read($file), "$file: $what");
			}
		}
		foreach ([
			'promise' => 'Reste gelöschter Inhalte in freien Seiten der Datenbankdatei, auch aus früheren Versionen, werden entfernt, wenn die Datei neu aufgebaut wird: in der Regel täglich, nachdem eine Seite ausgeliefert ist, spätestens beim ersten erfassten Aufruf, nachdem der letzte gelungene Neuaufbau mehr als zwei Tage zurückliegt, sofern der Neuaufbau gelingt; scheitert er wiederholt, zeigt das Dashboard eine Warnung.',
			'rest' => 'Wo die Antwort vorher abgeschlossen werden kann, wird ein Aufruf (Seitenaufruf, Klick, Aktion) nach der Antwort geschrieben und wartet bis zu 60 Sekunden auf einen laufenden Neuaufbau. Ohne `fastcgi_finish_request()` oder `litespeed_finish_request()` (cgi-fcgi, mod_php) wird er während der Anfrage geschrieben und wartet wie bisher höchstens 5 Sekunden; dauert ein Neuaufbau länger, wird dieser Aufruf nicht gezählt (Error-Log: `Kizami: database is locked`).',
		] as $what => $sentence) {
			Assert::assertStringContainsString($sentence, $read('docs/DATENSCHUTZ.md'), "DATENSCHUTZ.md: $what");
		}
		foreach (['README.md' => 'up to twice the file size', 'CHANGELOG.md' => 'up to twice the file size', 'docs/DATENSCHUTZ.md' => 'bis zur doppelten Dateigröße'] as $file => $space) {
			Assert::assertStringContainsString($space, $read($file), "$file: disk space");
		}
		foreach (['README.md', 'PRIVACY.md', 'CHANGELOG.md', 'docs/DATENSCHUTZ.md'] as $file) {
			Assert::assertDoesNotMatchRegularExpression('/space the size of the file|Speicherplatz in der Größe der Datei|keep their count|fehlt ihr Zähler/u', $read($file), "$file: nothing of the old wording");
		}
	}

	/**
	 * VACUUM fails after the day was claimed: no second attempt that day, the
	 * last success stays, and once it is more than 48 hours old the
	 * dashboard warns. The next day's attempt succeeds and the warning goes.
	 */
	public function testFailedRebuildKeepsTheDayAndWarnsOnTheDashboard(): void
	{
		$file = $this->database('vacuum-fails', 'wal', 3000, self::before('-3 days'), '2026-11-09');
		$store = new Store($file, 'wal');
		// A statement still running on the same connection: SQLite refuses VACUUM.
		$pending = (new ReflectionProperty($store, 'db'))->getValue($store)->query('SELECT id FROM kizami_events');
		$pending->fetchArray();
		$now = new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
		$error = '';
		try {
			$store->rebuildDaily($now);
		} catch (Throwable $e) {
			$error = $e->getMessage();
		}
		Assert::assertStringContainsString('cannot VACUUM', $error, 'counter-check: VACUUM failed');
		$pending->finalize();
		Assert::assertSame(['day' => '2026-11-12', 'done' => self::before('-3 days')], $store->lastRebuild(), 'day claimed, last success kept');
		Assert::assertGreaterThan(0, self::scalar($file, 'PRAGMA freelist_count'), 'not rebuilt');
		Assert::assertFalse($store->rebuildDaily($now->modify('+1 hour')), 'no second attempt the same day');

		Assert::assertTrue(Rebuild::failing($store->lastRebuild(), $now), 'failing: attempted, last success 3 days ago');
		I18n::setLanguage('de');
		$data = Analysis::data($store, new Names([], [], []), 7, []);
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		$html = (string)ob_get_clean();
		Assert::assertStringContainsString('Die Datenbankdatei wurde seit mehr als zwei Tagen nicht neu aufgebaut (zuletzt: 9.11.2026)', $html, 'the dashboard warns with the date of the last success');

		Assert::assertTrue($store->rebuildDaily($now->modify('+1 day')), 'the next day the rebuild succeeds');
		Assert::assertFalse(Rebuild::failing($store->lastRebuild(), $now->modify('+1 day')), 'success: no warning');
		$data = Analysis::data($store, new Names([], [], []), 7, []);
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		$html = (string)ob_get_clean();
		Assert::assertStringNotContainsString('nicht neu aufgebaut', $html, 'success: the dashboard does not warn');

		// When to warn.
		foreach ([
			'never attempted' => [['day' => '', 'done' => ''], false],
			'attempted, never succeeded' => [['day' => '2026-11-12', 'done' => ''], true],
			'succeeded 47 hours ago, failed today' => [['day' => '2026-11-12', 'done' => self::before('-47 hours')], false],
			'succeeded 49 hours ago, failed since' => [['day' => '2026-11-11', 'done' => self::before('-49 hours')], true],
			'no request for three days' => [['day' => '2026-11-09', 'done' => self::before('-3 days')], false],
		] as $case => [$state, $expected]) {
			Assert::assertSame($expected, Rebuild::failing($state, $now), $case);
		}
	}

	public function testFailingRebuildKeepsThePageWhole(): void
	{
		// The Store cannot be opened after the response.
		$file = $this->database('throws', 'wal');
		$result = $this->request($file, ['kind' => 'bot-throws', 'fpm' => true]);
		Assert::assertSame("PAGE\nFINISH\n", $result['out'], 'opening fails: the page is whole');
		Assert::assertSame(0, $result['code'], 'opening fails: exit code 0');
		Assert::assertStringContainsString('Kizami: rebuild after the response failed: Kizami test: the database cannot be opened', $result['log'], 'opening fails: logged');

		// A real SQLite error: another request holds the write lock longer than
		// the busy timeout, so the rebuild fails with "database is locked".
		foreach ([false, true] as $fpm) {
			$file = $this->database('locked-' . (int)$fpm, 'wal');
			$result = $this->request($file, ['kind' => 'bot', 'lock' => true, 'fpm' => $fpm]);
			Assert::assertSame($fpm ? "PAGE\nFINISH\n" : "PAGE\n", $result['out'], 'locked: the page is whole');
			Assert::assertSame(0, $result['code'], 'locked: exit code 0');
			Assert::assertStringContainsString('Kizami: rebuild after the response failed: database is locked', $result['log'], 'locked: logged');
			Assert::assertGreaterThan(0, self::scalar($file, 'PRAGMA freelist_count'), 'locked: not rebuilt');
			Assert::assertSame(['day' => '', 'done' => ''], (new Store($file, 'wal'))->lastRebuild(), 'locked: nothing claimed, the next request tries again');
		}

		// A failing check after a counted visit does not reach the caller.
		$store = new Store($this->database('check', 'wal'), 'wal');
		(new ReflectionProperty($store, 'db'))->getValue($store)->exec('DROP TABLE kizami_rebuild');
		$log = $this->root . '/check.log';
		ini_set('error_log', $log);
		Rebuild::afterCount($store, $this->root . '/check/' . Store::FILE, new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
		Assert::assertStringContainsString('Kizami: rebuild check failed', (string)@file_get_contents($log), 'a failing check is logged, not thrown');
	}

	/**
	 * A rebuild that holds the write lock longer than the busy timeout (5 s):
	 * where the response can be ended first, the page view is written after
	 * it and waits, nobody waits for it, and it is kept. Without a way to end
	 * the response the write stays in the request: the visitor waits at most
	 * 5 s as before, and the page view is lost (logged). That is the rest the
	 * README, PRIVACY, DATENSCHUTZ and CHANGELOG name.
	 */
	public function testLongRebuildCostsNoCountWhereTheResponseEndsFirst(): void
	{
		foreach ([true, false] as $fpm) {
			[$root, $file] = $this->kirbyRoot('kirby-' . (int)$fpm);

			// Another connection holds the write lock for 7 s, like a long rebuild.
			$lock = new SQLite3($file);
			$lock->exec('BEGIN IMMEDIATE');
			$lock->exec("INSERT INTO kizami_discarded (day, hits) VALUES ('2000-01-01', 1)");
			$start = microtime(true);
			$finished = "$root/finished";
			$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/count-request.php', json_encode([
				'root' => $root, 'log' => "$root/php-errors.log", 'finished' => $finished, 'fpm' => $fpm,
			])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$exited = null;
			while (microtime(true) - $start < 7) {
				if ($exited === null && !proc_get_status($process)['running']) {
					$exited = microtime(true);
				}
				usleep(50000);
			}
			$lock->exec('ROLLBACK');
			$lock->close();
			$out = (string)stream_get_contents($pipes[1]);
			$err = (string)stream_get_contents($pipes[2]);
			proc_close($process);
			$took = ($exited ?? microtime(true)) - $start;
			$log = (string)@file_get_contents("$root/php-errors.log");
			$rows = self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE type='page'");
			Assert::assertSame('', $err, 'child without stderr');
			if ($fpm) {
				Assert::assertSame("PAGE\nFINISH\n", $out, 'FPM: the whole page, then the response ends');
				Assert::assertLessThan(3, (float)@file_get_contents($finished) - $start, 'FPM: the response ended while the lock was still held, nobody waited');
				Assert::assertSame(1, $rows, 'FPM: the page view waited for the lock after the response and is kept');
				Assert::assertSame('', $log, 'FPM: no error');
			} else {
				Assert::assertSame("PAGE\n", $out, 'no finisher: the whole page');
				Assert::assertSame(0, $rows, 'no finisher: after 5 s in the request the page view is lost');
				Assert::assertStringContainsString('Kizami: database is locked', $log, 'no finisher: the lost page view is logged');
				Assert::assertLessThan(6.5, $took, 'no finisher: the visitor waited no longer than the busy timeout, not for the whole rebuild');
			}
		}
	}

	public function testParallelWritesKeepEveryCount(): void
	{
		foreach (['wal', 'delete'] as $journal) {
			$file = $this->database("parallel-$journal", $journal, 30000);
			$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/rebuild-request.php', json_encode([
				'kind' => 'writer', 'db' => $file, 'journal' => $journal, 'now' => self::NOW, 'state' => "$file.state", 'log' => "$file.log",
			])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			for ($i = 0; $i < 500 && !is_file(dirname($file) . '/writer-ready'); $i++) {
				usleep(10000);
			}
			usleep(200000);
			$rebuilt = 0;
			$failure = null;
			try {
				$store = new Store($file, $journal);
				// Three days, three rebuilds, while page views keep coming in.
				foreach (['+0 days', '+1 day', '+2 days'] as $day) {
					$raw = new SQLite3($file);
					$raw->busyTimeout(5000);
					$raw->exec('DELETE FROM kizami_events WHERE id % 7 = 0 AND path <> \'/parallel\'');
					$raw->close();
					$rebuilt += (int)$store->rebuildDaily(new DateTimeImmutable(self::NOW . ' ' . $day, new DateTimeZone('UTC')));
					usleep(100000);
				}
				unset($store);
			} catch (Throwable $e) {
				$failure = $e->getMessage();
			} finally {
				// The writer stops in every case, a failure included.
				touch(dirname($file) . '/stop');
			}
			$writer = json_decode(trim((string)stream_get_contents($pipes[1])), true);
			$stderr = (string)stream_get_contents($pipes[2]);
			proc_close($process);
			Assert::assertSame('', $stderr, "$journal: writer without stderr");
			Assert::assertNull($failure, "$journal: no rebuild failed");
			Assert::assertSame(3, $rebuilt, "$journal: three rebuilds ran");
			Assert::assertSame([], $writer['errors'] ?? null, "$journal: no write failed during the rebuilds");
			Assert::assertGreaterThan(20, $writer['ok'], "$journal: the writer wrote throughout");
			Assert::assertSame($writer['ok'], self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE path='/parallel'"), "$journal: every page view written in parallel is in the file");
			Assert::assertSame('ok', self::scalar($file, 'PRAGMA integrity_check'), "$journal: file intact");
		}
	}

	/**
	 * The real PHP-FPM, no stub: fastcgi_finish_request() ends the response
	 * (END_REQUEST reaches the web server) while the page view and the rebuild
	 * still wait for a write lock; both happen once the lock is free. Needs a
	 * php-fpm binary (PHP_FPM, or next to PHP); skipped without one.
	 */
	public function testRealFpmEndsTheResponseFirst(): void
	{
		$fpm = getenv('PHP_FPM') ?: '';
		foreach ([dirname(PHP_BINARY, 2) . '/sbin/php-fpm', '/usr/sbin/php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '/usr/local/sbin/php-fpm'] as $candidate) {
			if ($fpm === '' && is_executable($candidate)) {
				$fpm = $candidate;
			}
		}
		if (getenv('PHP_FPM')) {
			Assert::assertTrue(is_executable($fpm), "PHP_FPM is set, but $fpm cannot be run");
		} elseif ($fpm === '') {
			$this->markTestSkipped('no php-fpm binary (set PHP_FPM)');
		}
		$socket = "$this->root/fpm.sock";
		$conf = "$this->root/fpm.conf";
		$root = function_exists('posix_getuid') && posix_getuid() === 0;
		file_put_contents($conf, "[global]\nerror_log = $this->root/fpm.log\ndaemonize = no\n[www]\nlisten = $socket\n"
			. ($root ? "user = root\ngroup = root\n" : '') . "pm = static\npm.max_children = 2\ncatch_workers_output = yes\nclear_env = no\n");
		$server = proc_open([$fpm, '-F', '-y', $conf, ...($root ? ['-R'] : [])], [1 => ['file', "$this->root/fpm.out", 'a'], 2 => ['file', "$this->root/fpm.out", 'a']], $unused);
		$lock = null;
		$hold = function (string $file) use (&$lock): void {
			// Another connection holds the write lock, like a running rebuild.
			$lock = new SQLite3($file);
			$lock->exec('BEGIN IMMEDIATE');
			$lock->exec("INSERT INTO kizami_discarded (day, hits) VALUES ('2000-01-01', 1)");
		};
		$release = function () use (&$lock): void {
			$lock->exec('ROLLBACK');
			$lock->close();
			$lock = null;
		};
		$diagnosis = fn (array $response, string $log): string => json_encode([$response, @file_get_contents("$this->root/fpm.out"), @file_get_contents("$this->root/fpm.log"), @file_get_contents($log)]);
		try {
			for ($i = 0; $i < 100 && !file_exists($socket); $i++) {
				usleep(50000);
			}
			Assert::assertFileExists($socket, 'php-fpm started: ' . @file_get_contents("$this->root/fpm.out"));

			// A counted page view through Runtime::write(): the response ends,
			// the row is written after it, once the lock is free.
			[$kirby, $file] = $this->kirbyRoot('fpm-count');
			file_put_contents("$kirby/options.json", json_encode(['root' => $kirby, 'log' => "$kirby/php-errors.log", 'finished' => "$kirby/finished"]));
			$hold($file);
			$response = self::fastCgi($socket, ['SCRIPT_FILENAME' => dirname(__DIR__) . '/tests/helpers/count-request.php', 'REQUEST_METHOD' => 'GET', 'KIZAMI_TEST_OPTIONS' => "$kirby/options.json"], 3.0);
			Assert::assertTrue($response['ended'], 'page view: END_REQUEST while the lock was held, nobody waits for the write; ' . $diagnosis($response, "$kirby/php-errors.log"));
			Assert::assertStringEndsWith("\r\n\r\nPAGE\n", $response['stdout'], 'page view: the whole page');
			Assert::assertSame(0, self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE type='page'"), 'page view: not written yet');
			$release();
			for ($i = 0; $i < 100 && self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE type='page'") === 0; $i++) {
				usleep(100000);
			}
			Assert::assertSame(1, self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE type='page'"), 'page view: written after the response');
			Assert::assertSame('', (string)@file_get_contents("$kirby/php-errors.log"), 'page view: no error');

			// A bot request: its page comes from an output buffer, the response
			// ends, the rebuild follows once the lock is free.
			$file = $this->database('fpm-bot', 'wal', 3000, self::before('-1 hour'), '2026-11-11');
			file_put_contents("$file.options", json_encode([
				'kind' => 'bot', 'buffer' => true, 'db' => $file, 'journal' => 'wal', 'now' => self::NOW, 'state' => "$file.state", 'log' => "$file.log",
			]));
			$hold($file);
			$response = self::fastCgi($socket, ['SCRIPT_FILENAME' => dirname(__DIR__) . '/tests/helpers/rebuild-request.php', 'REQUEST_METHOD' => 'GET', 'KIZAMI_TEST_OPTIONS' => "$file.options"], 3.0);
			Assert::assertTrue($response['ended'], 'rebuild: END_REQUEST while the lock was held; ' . $diagnosis($response, "$file.log"));
			Assert::assertStringEndsWith("\r\n\r\nPAGE\n", $response['stdout'], 'rebuild: the whole page, sent from the output buffer');
			Assert::assertGreaterThan(0, self::scalar($file, 'PRAGMA freelist_count'), 'rebuild: not yet');
			$release();
			for ($i = 0; $i < 100 && self::scalar($file, 'SELECT done FROM kizami_rebuild') !== self::NOW; $i++) {
				usleep(100000);
			}
			Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'rebuild: after the response');
			Assert::assertSame('', (string)@file_get_contents("$file.log"), 'rebuild: no error');
		} finally {
			$lock?->close();
			proc_terminate($server);
			proc_close($server);
		}
	}

	/** The visitor closes the connection before the page is sent: the rebuild still runs. */
	public function testRebuildGoesOnWhenTheVisitorLeaves(): void
	{
		$file = $this->database('gone', 'wal', 3000, self::before('-49 hours'), '2026-11-10');
		$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/rebuild-request.php', json_encode([
			'kind' => 'count', 'buffer' => true, 'db' => $file, 'journal' => 'wal', 'now' => self::NOW, 'state' => "$file.state", 'log' => "$file.log",
		])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		fclose($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		proc_close($process);
		Assert::assertSame('', $err, 'no stderr');
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'rebuilt although nobody read the page');
		Assert::assertSame('2026-11-12', self::scalar($file, 'SELECT day FROM kizami_rebuild'), 'day claimed');
	}

	/**
	 * A storage directory removed after the request (a test cleaning up, a
	 * site taken down): the rebuild after the response neither creates the
	 * directory nor a database again, and logs nothing.
	 */
	public function testRebuildNeverCreatesTheDatabase(): void
	{
		foreach (['bot', 'count'] as $kind) {
			$file = $this->database("removed-$kind", 'wal');
			$result = $this->request($file, ['kind' => $kind, 'remove' => true, 'log' => "$this->root/removed-$kind.log", 'state' => "$this->root/removed-$kind.state"]);
			Assert::assertSame("PAGE\n", $result['out'], "$kind: the whole page");
			Assert::assertDirectoryDoesNotExist(dirname($file), "$kind: the removed directory is not created again");
			Assert::assertSame('', $result['log'], "$kind: nothing logged");
		}
	}

	public function testRebuildsAtMostOncePerDay(): void
	{
		$file = $this->database('daily', 'wal');
		$store = new Store($file, 'wal');
		$now = new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
		Assert::assertTrue($store->rebuildDaily($now), 'first attempt of the day rebuilds');
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'rebuilt: no free pages');
		Assert::assertSame(['day' => '2026-11-12', 'done' => self::NOW], $store->lastRebuild(), 'day claimed (Berlin), success recorded (UTC)');

		// New free pages the same day: the second attempt does nothing.
		$raw = new SQLite3($file);
		$raw->exec('DELETE FROM kizami_events WHERE id % 2 = 0');
		$raw->close();
		$free = self::scalar($file, 'PRAGMA freelist_count');
		Assert::assertGreaterThan(0, $free, 'counter-check: free pages again');
		Assert::assertFalse($store->rebuildDaily($now->modify('+11 hours 59 minutes')), 'same Berlin day (23:59): no second rebuild');
		Assert::assertSame($free, self::scalar($file, 'PRAGMA freelist_count'), 'same day: the file was not rebuilt');
		Assert::assertTrue($store->rebuildDaily($now->modify('+12 hours')), 'next Berlin day (00:00): rebuilds again');
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'next day: rebuilt');
		unset($store);

		// The same through requests on a server that ends the response first.
		$file = $this->database('requests', 'wal');
		$this->request($file, ['kind' => 'count', 'fpm' => true]);
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'first counted visit of the day rebuilds');
		Assert::assertSame('2026-11-12', self::scalar($file, 'SELECT day FROM kizami_rebuild'), 'first counted visit claims the day');
		$raw = new SQLite3($file);
		$raw->exec('DELETE FROM kizami_events WHERE id % 2 = 0');
		$raw->close();
		$free = self::scalar($file, 'PRAGMA freelist_count');
		foreach (['count', 'bot'] as $kind) {
			$this->request($file, ['kind' => $kind, 'fpm' => true, 'now' => self::before('+5 hours')]);
			Assert::assertSame($free, self::scalar($file, 'PRAGMA freelist_count'), "later $kind request the same day: not rebuilt again");
		}

		// Two attempts at the same moment: exactly one rebuilds.
		$file = $this->database('parallel', 'wal', 20000);
		$processes = [];
		foreach ([1, 2] as $i) {
			$processes[$i] = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/rebuild-request.php', json_encode([
				'kind' => 'rebuild', 'db' => $file, 'journal' => 'wal', 'now' => self::NOW, 'state' => "$file.state", 'log' => "$file.log",
			])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
		}
		usleep(300000);
		touch(dirname($file) . '/go');
		$results = [];
		foreach ($processes as $i => $process) {
			$results[] = trim((string)stream_get_contents($pipes[$i][1])) . trim((string)stream_get_contents($pipes[$i][2]));
			proc_close($process);
		}
		sort($results);
		Assert::assertSame(['false', 'true'], $results, 'two parallel attempts: one rebuilds, the other sees the claimed day');
	}

	public function testRebuildsOnlyAfterTheResponse(): void
	{
		// PHP-FPM: the response is ended first, then the file is rebuilt.
		$file = $this->database('fpm', 'wal');
		$result = $this->request($file, ['kind' => 'count', 'fpm' => true, 'buffer' => true]);
		Assert::assertSame("PAGE\nFINISH\n", $result['out'], 'the page, held in an output buffer, is out before the response is ended');
		Assert::assertSame(0, $result['code'], 'exit code 0');
		$free = $result['probes']['end of script']['free'] ?? 0;
		Assert::assertGreaterThan(0, $free, 'nothing rebuilt while the page is rendered');
		Assert::assertSame(['day' => '', 'free' => $free], array_intersect_key($result['probes']['finish'] ?? [], ['day' => 1, 'free' => 1]), 'nothing rebuilt or claimed when the response is ended');
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'rebuilt after the response');
		Assert::assertSame('', $result['log'], 'no error');

		// Without a way to end the response (cgi-fcgi, here the CLI): a bot's
		// request rebuilds after its page; nobody waits for it.
		$file = $this->database('cgi-bot', 'delete', 3000, self::before('-1 hour'), '2026-11-11');
		$result = $this->request($file, ['kind' => 'bot', 'journal' => 'delete']);
		Assert::assertSame("PAGE\n", $result['out'], 'bot: the whole page');
		Assert::assertGreaterThan(0, $result['probes']['end of script']['free'] ?? 0, 'bot: nothing rebuilt while the page is rendered');
		Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), 'bot: rebuilt after the page');

		// A counted visit there leaves it to a bot while the last rebuild is recent.
		$file = $this->database('cgi-count', 'wal', 3000, self::before('-1 hour'), '2026-11-11');
		$result = $this->request($file, ['kind' => 'count']);
		Assert::assertSame("PAGE\n", $result['out'], 'counted visit: the whole page');
		Assert::assertGreaterThan(0, self::scalar($file, 'PRAGMA freelist_count'), 'counted visit without a way to end the response: no rebuild, a visitor would wait');
		Assert::assertSame('2026-11-11', self::scalar($file, 'SELECT day FROM kizami_rebuild'), 'counted visit: the day is not claimed');
	}
}
