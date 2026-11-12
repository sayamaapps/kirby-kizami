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
use Sayamaapps\Kizami\Spool;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Analysis::class)]
#[CoversClass(\Sayamaapps\Kizami\Rebuild::class)]
#[CoversClass(\Sayamaapps\Kizami\Runtime::class)]
#[CoversClass(\Sayamaapps\Kizami\Spool::class)]
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

	/** The size of $file now, not from PHP's stat cache. */
	private static function bytes(string $file): int
	{
		clearstatcache(true, $file);
		return (int)filesize($file);
	}

	/** @return list<mixed> the first column of every row */
	private static function column(string $file, string $sql): array
	{
		$db = new SQLite3($file);
		$db->busyTimeout(5000);
		$result = $db->query($sql);
		$values = [];
		while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
			$values[] = $row[0];
		}
		$result->finalize();
		unset($result);
		$db->close();
		return $values;
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
	private function kirbyRoot(string $name, string $journal = 'wal'): array
	{
		$root = $this->root . '/' . $name;
		foreach (['public', 'content', 'site', 'storage/kizami'] as $dir) {
			mkdir("$root/$dir", 0700, true);
		}
		$file = "$root/storage/kizami/" . Store::FILE;
		$store = new Store($file, $journal);
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
	 * the promise, the spool and its protection in the same words, with the
	 * numbers the code uses, and nothing of the earlier drafts.
	 */
	public function testDocsSayTheSame(): void
	{
		Assert::assertSame([48, 5000, 'spool.jsonl'], [Rebuild::OVERDUE_HOURS, Store::BUSY_TIMEOUT_MS, Spool::FILE], 'the numbers and the name the texts use');
		$read = fn (string $file): string => preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__) . '/' . $file));
		$english = [
			'promise' => 'Leftover bytes of deleted content in free pages of the database file, including those left by older versions, are removed when the file is rebuilt: as a rule daily, after a page has been delivered, at the latest at the first counted visit once the last successful rebuild is more than two days old, provided the rebuild succeeds; if it keeps failing, the dashboard shows a warning.',
			'spool' => 'While the database is locked, for example during a rebuild, a hit (page view, click, action) waits at most 5 seconds, as before. If it is still locked then, the hit goes to a spool file next to the database (`storage/kizami/spool.jsonl`, the same fields as in the database, nothing more) and is written at the next successful write or at the end of the rebuild; a click that needs a page view of the same visit is decided then. Only if the spool file cannot be written either is the hit lost; the error log then contains `database is locked`.',
			'protection' => 'The spool file sits outside the web root like the database and is readable by its owner only; it is emptied when it is read, and lines older than the retention period are dropped then. Snapshots do not contain it; leave it out of file backups like `secret.txt`, and delete it together with the database when counting is switched off for good.',
		];
		foreach (['README.md', 'PRIVACY.md', 'CHANGELOG.md'] as $file) {
			foreach ($english as $what => $sentence) {
				Assert::assertStringContainsString($sentence, $read($file), "$file: $what");
			}
		}
		foreach ([
			'promise' => 'Reste gelöschter Inhalte in freien Seiten der Datenbankdatei, auch aus früheren Versionen, werden entfernt, wenn die Datei neu aufgebaut wird: in der Regel täglich, nachdem eine Seite ausgeliefert ist, spätestens beim ersten erfassten Aufruf, nachdem der letzte gelungene Neuaufbau mehr als zwei Tage zurückliegt, sofern der Neuaufbau gelingt; scheitert er wiederholt, zeigt das Dashboard eine Warnung.',
			'spool' => 'Solange die Datenbank gesperrt ist, etwa während eines Neuaufbaus, wartet ein Aufruf (Seitenaufruf, Klick, Aktion) wie bisher höchstens 5 Sekunden. Ist sie dann noch gesperrt, kommt der Aufruf in eine Zwischendatei neben der Datenbank (`storage/kizami/spool.jsonl`, dieselben Angaben wie in der Datenbank, nichts weiter) und wird beim nächsten gelungenen Schreiben oder am Ende des Neuaufbaus gespeichert; ein Klick, der einen Seitenaufruf desselben Besuchs braucht, wird erst dann entschieden. Nur wenn auch die Zwischendatei nicht geschrieben werden kann, geht der Aufruf verloren; das Error-Log enthält dann `database is locked`.',
			'protection' => 'Die Zwischendatei liegt wie die Datenbank außerhalb des Web-Verzeichnisses und ist nur für ihren Eigentümer lesbar; sie wird beim Einlesen geleert, Zeilen älter als die Aufbewahrungsfrist werden dabei verworfen. Snapshots enthalten sie nicht; aus Dateisicherungen wie `secret.txt` ausschließen und zusammen mit der Datenbank löschen, wenn die Erfassung dauerhaft abgeschaltet wird.',
		] as $what => $sentence) {
			Assert::assertStringContainsString($sentence, $read('docs/DATENSCHUTZ.md'), "DATENSCHUTZ.md: $what");
		}
		foreach (['README.md' => 'up to twice the file size', 'CHANGELOG.md' => 'up to twice the file size', 'docs/DATENSCHUTZ.md' => 'bis zur doppelten Dateigröße'] as $file => $space) {
			Assert::assertStringContainsString($space, $read($file), "$file: disk space");
		}
		foreach (['README.md', 'PRIVACY.md', 'CHANGELOG.md', 'docs/DATENSCHUTZ.md'] as $file) {
			Assert::assertDoesNotMatchRegularExpression('/space the size of the file|Speicherplatz in der Größe der Datei|keep their count|fehlt ihr Zähler|60 seconds|60 Sekunden|WRITE_WAIT|written after the response|nach der Antwort geschrieben|error log: `Kizami: database is locked`/u', $read($file), "$file: nothing of the earlier drafts");
		}
	}

	/**
	 * VACUUM fails after the day was claimed: no second attempt that day, the
	 * last success stays, and only once the first failed attempt is more than
	 * 48 hours old does the dashboard warn (an update that failed its first
	 * attempt today does not). The next success removes the warning.
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
		// PHP 8.5 keeps the statement until the result object is gone.
		$pending->finalize();
		unset($pending);
		Assert::assertSame(['day' => '2026-11-12', 'done' => self::before('-3 days'), 'since' => self::NOW], $store->lastRebuild(), 'day claimed, last success kept, first failed attempt recorded');
		Assert::assertGreaterThan(0, self::scalar($file, 'PRAGMA freelist_count'), 'not rebuilt');
		Assert::assertFalse($store->rebuildDaily($now->modify('+1 hour')), 'no second attempt the same day');
		Assert::assertFalse(Rebuild::failing($store->lastRebuild(), $now), 'the first failed attempt is young: no warning yet');
		Assert::assertTrue(Rebuild::failing($store->lastRebuild(), $now->modify('+49 hours')), 'failing for more than 48 hours: warning');

		// Attempts have failed for three days: the dashboard warns.
		$db = new SQLite3($file);
		$db->exec("UPDATE kizami_rebuild SET since = '" . self::before('-3 days') . "'");
		$db->close();
		I18n::setLanguage('de');
		$data = Analysis::data($store, new Names([], [], []), 7, []);
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		$html = (string)ob_get_clean();
		Assert::assertStringContainsString('Die Datenbankdatei wurde seit mehr als zwei Tagen nicht neu aufgebaut (zuletzt: 9.11.2026)', $html, 'the dashboard warns with the date of the last success');

		Assert::assertTrue($store->rebuildDaily($now->modify('+1 day')), 'the next day the rebuild succeeds');
		Assert::assertSame('', $store->lastRebuild()['since'], 'success: no failed attempt open');
		Assert::assertFalse(Rebuild::failing($store->lastRebuild(), $now->modify('+1 day')), 'success: no warning');
		$data = Analysis::data($store, new Names([], [], []), 7, []);
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		$html = (string)ob_get_clean();
		Assert::assertStringNotContainsString('nicht neu aufgebaut', $html, 'success: the dashboard does not warn');

		// When to warn.
		foreach ([
			'never attempted' => [['day' => '', 'done' => '', 'since' => ''], false],
			'first attempt after the update failed today' => [['day' => '2026-11-12', 'done' => '', 'since' => self::NOW], false],
			'first attempt after the update failed 49 hours ago' => [['day' => '2026-11-12', 'done' => '', 'since' => self::before('-49 hours')], true],
			'attempts failing for 47 hours' => [['day' => '2026-11-12', 'done' => self::before('-3 days'), 'since' => self::before('-47 hours')], false],
			'attempts failing for 49 hours' => [['day' => '2026-11-12', 'done' => self::before('-3 days'), 'since' => self::before('-49 hours')], true],
			'no request for three days after a success' => [['day' => '2026-11-09', 'done' => self::before('-3 days'), 'since' => ''], false],
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
			Assert::assertSame(['day' => '', 'done' => '', 'since' => ''], (new Store($file, 'wal'))->lastRebuild(), 'locked: nothing claimed, the next request tries again');
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
	 * The database stays locked for 20 s (a long rebuild), in WAL and in
	 * rollback-journal mode, where the lock also stops readers and the
	 * opening of the database. Every hit waits at most 5 s and goes to the
	 * spool file; once the lock is gone, two parallel requests read the spool
	 * in: every hit is counted once, the link filter decides the clicks
	 * against the spooled page view, and the spool is empty.
	 */
	public function testLockedDatabaseSpoolsEveryHit(): void
	{
		foreach (['wal', 'delete'] as $journal) {
			[$root, $file] = $this->kirbyRoot("spool-$journal", $journal);
			$request = function (array $options) use ($root, $journal): array {
				$log = "$root/" . bin2hex(random_bytes(4)) . '.log';
				$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/count-request.php', json_encode($options + [
					'root' => $root, 'log' => $log, 'finished' => "$root/finished", 'journal' => $journal,
				])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
				return [$process, $pipes, $log, microtime(true)];
			};
			$finish = function (array $started): array {
				[$process, $pipes, $log, $start] = $started;
				$out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
				proc_close($process);
				return [$out, microtime(true) - $start, (string)@file_get_contents($log)];
			};

			$lock = new SQLite3($file);
			$lock->exec($journal === 'wal' ? 'BEGIN IMMEDIATE' : 'BEGIN EXCLUSIVE');
			$lock->exec("INSERT INTO kizami_discarded (day, hits) VALUES ('2000-01-01', 1)");
			$locked = microtime(true);
			// A page view first, then three clicks at once: one of the same
			// visit without a confirmed user action, one of another visit
			// without, one with.
			$results = [$finish($request(['path' => '/a']))];
			$clicks = [$request(['click' => false, 'path' => '/a']), $request(['click' => false, 'path' => '/b', 'ip' => '198.51.100.8']), $request(['click' => true, 'path' => '/a'])];
			foreach ($clicks as $started) {
				$results[] = $finish($started);
			}
			foreach ($results as $i => [$out, $took, $log]) {
				Assert::assertSame("PAGE\n", $out, "$journal #$i: the whole page");
				Assert::assertLessThan(6.0, $took, "$journal #$i: waited at most the busy timeout of 5 s (plus start-up)");
				Assert::assertSame('', $log, "$journal #$i: nothing logged, nothing lost");
			}
			Assert::assertCount(4, file(Spool::path($file), FILE_IGNORE_NEW_LINES), "$journal: four hits in the spool");
			Assert::assertSame('0600', substr(sprintf('%o', fileperms(Spool::path($file))), -4), "$journal: the spool is readable by its owner only");
			while (microtime(true) - $locked < 20) {
				usleep(100000);
			}
			$lock->exec('ROLLBACK');
			$lock->close();

			// Two requests at once read the spool in; each counts its own page view.
			$readers = [$request(['path' => '/c']), $request(['path' => '/d'])];
			foreach ($readers as $started) {
				[$out, , $log] = $finish($started);
				Assert::assertSame(['PAGE', ''], [trim($out), $log], "$journal: reader without error");
			}
			$paths = self::column($file, "SELECT path FROM kizami_events WHERE type='page' ORDER BY path");
			Assert::assertSame(['/a', '/c', '/d'], $paths, "$journal: every page view once, none twice");
			Assert::assertSame(2, self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE type='redirect'"), "$journal: the confirmed click and the click after the spooled page view count");
			Assert::assertSame(1, self::scalar($file, "SELECT hits FROM kizami_discarded WHERE day <> '2000-01-01'"), "$journal: the click without a page view of its visit is discarded");
			Assert::assertSame(0, self::bytes(Spool::path($file)), "$journal: the spool is empty");
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
	 * The real PHP-FPM, no stub. A page view during a lock waits at most 5 s
	 * and goes to the spool, the next request reads it in. The rebuild after a
	 * bot's request: fastcgi_finish_request() ends the response (END_REQUEST
	 * reaches the web server) while the rebuild still waits for the lock.
	 * Needs a php-fpm binary (PHP_FPM, or next to PHP); skipped without one.
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

			// A counted page view through Runtime::write() under the real FPM:
			// it waits at most 5 s for the lock, then goes to the spool; the
			// next request reads it in.
			[$kirby, $file] = $this->kirbyRoot('fpm-count');
			$options = function (string $path) use ($kirby): string {
				file_put_contents("$kirby/$path.json", json_encode(['root' => $kirby, 'log' => "$kirby/php-errors.log", 'finished' => "$kirby/finished", 'path' => "/$path"]));
				return "$kirby/$path.json";
			};
			$hold($file);
			$start = microtime(true);
			$response = self::fastCgi($socket, ['SCRIPT_FILENAME' => dirname(__DIR__) . '/tests/helpers/count-request.php', 'REQUEST_METHOD' => 'GET', 'KIZAMI_TEST_OPTIONS' => $options('first')], 8.0);
			$took = microtime(true) - $start;
			Assert::assertTrue($response['ended'], 'page view: the response ended; ' . $diagnosis($response, "$kirby/php-errors.log"));
			Assert::assertStringEndsWith("\r\n\r\nPAGE\n", $response['stdout'], 'page view: the whole page');
			Assert::assertLessThan(6.0, $took, 'page view: the visitor waited at most the busy timeout of 5 s');
			Assert::assertCount(1, file(Spool::path($file), FILE_IGNORE_NEW_LINES), 'page view: in the spool');
			$release();
			self::fastCgi($socket, ['SCRIPT_FILENAME' => dirname(__DIR__) . '/tests/helpers/count-request.php', 'REQUEST_METHOD' => 'GET', 'KIZAMI_TEST_OPTIONS' => $options('second')], 8.0);
			Assert::assertSame(['/first', '/second'], self::column($file, "SELECT path FROM kizami_events WHERE type='page' ORDER BY path"), 'page view: read in by the next request, each once');
			Assert::assertSame(0, self::bytes(Spool::path($file)), 'page view: spool empty');
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
		Assert::assertSame(['day' => '2026-11-12', 'done' => self::NOW, 'since' => ''], $store->lastRebuild(), 'day claimed (Berlin), success recorded (UTC)');

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

	/**
	 * Reading the spool: at the end of a rebuild too; an unreadable line is
	 * skipped and logged without its content, a line older than the retention
	 * period dropped. When the spool cannot be written either, the hit is lost
	 * and the log says `database is locked`, with the prefix "Kizami:" once.
	 */
	public function testSpoolIsReadAndKeepsNothingStale(): void
	{
		$file = $this->database('spool-read', 'wal');
		$at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		Assert::assertTrue(Spool::add($file, ['type' => 'page', 'action' => 'view', 'path' => '/spooled', 'session' => 's', 'created_at' => $at]), 'spooled');
		Assert::assertTrue(Spool::add($file, ['type' => 'page', 'action' => 'view', 'path' => '/ancient', 'session' => 's', 'created_at' => '2001-01-01 10:00:00']), 'spooled, older than the retention period');
		Assert::assertTrue(Spool::add($file, ['type' => 'event', 'event' => 'download', 'detail' => 'x.pdf', 'path' => '/', 'session' => 's', 'created_at' => $at]), 'an action spooled');
		file_put_contents(Spool::path($file), "{not json SECRET-DETAIL\n" . json_encode(['row' => ['type' => 'nonsense', 'created_at' => $at]]) . "\n", FILE_APPEND);
		$log = "$this->root/spool-read.log";
		ini_set('error_log', $log);
		$store = new Store($file, 'wal');
		Assert::assertTrue($store->rebuildDaily(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))), 'the rebuild ran');
		Assert::assertSame(['/spooled'], self::column($file, "SELECT path FROM kizami_events WHERE path IN ('/spooled', '/ancient')"), 'the end of the rebuild read the spool; the stale line is dropped');
		Assert::assertSame(1, self::scalar($file, "SELECT COUNT(*) FROM kizami_custom_events WHERE detail='x.pdf'"), 'the action is written');
		Assert::assertSame(0, self::bytes(Spool::path($file)), 'spool empty');
		$logged = (string)@file_get_contents($log);
		Assert::assertStringContainsString('Kizami: 2 unreadable spool line(s) skipped', $logged, 'unreadable lines logged');
		Assert::assertStringNotContainsString('SECRET-DETAIL', $logged, 'without their content');
		Assert::assertSame(0, $store->readSpool(), 'nothing left to read');

		// The spool cannot be written either (here a directory in its place):
		// the hit is lost, and the log says why.
		[$root, $file] = $this->kirbyRoot('spool-blocked');
		mkdir(Spool::path($file));
		$lock = new SQLite3($file);
		$lock->exec('BEGIN IMMEDIATE');
		$lock->exec("INSERT INTO kizami_discarded (day, hits) VALUES ('2000-01-01', 1)");
		$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tests/helpers/count-request.php', json_encode(['root' => $root, 'log' => "$root/php-errors.log", 'finished' => "$root/finished"])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
		proc_close($process);
		$lock->exec('ROLLBACK');
		$lock->close();
		$logged = (string)@file_get_contents("$root/php-errors.log");
		Assert::assertSame("PAGE\n", $out, 'the whole page');
		Assert::assertStringContainsString('database is locked', $logged, 'the log says database is locked');
		Assert::assertStringContainsString('Kizami: spool file not writable, hit lost', $logged, 'and that the hit is lost');
		Assert::assertStringNotContainsString('Kizami: Kizami:', $logged, 'the prefix once');
		Assert::assertSame(0, self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE type='page'"), 'lost');

		// Store's own messages keep their prefix once.
		ini_set('error_log', $log);
		@unlink($log);
		(new ReflectionMethod(\Sayamaapps\Kizami\Runtime::class, 'logWriteError'))->invoke(null, new RuntimeException('Kizami: INSERT failed: disk full'));
		(new ReflectionMethod(\Sayamaapps\Kizami\Runtime::class, 'logWriteError'))->invoke(null, new RuntimeException('disk full'));
		$logged = (string)@file_get_contents($log);
		Assert::assertStringContainsString('Kizami: INSERT failed: disk full', $logged, 'Store message logged');
		Assert::assertStringNotContainsString('Kizami: Kizami:', $logged, 'not twice');
		Assert::assertStringContainsString('Kizami: disk full', $logged, 'a plain message gets the prefix');
	}
}
