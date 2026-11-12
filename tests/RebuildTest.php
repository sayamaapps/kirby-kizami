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
use Sayamaapps\Kizami\Rebuild;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Rebuild::class)]
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
	 * One request in a child process. Returns its output, exit code, error
	 * log and the probes (state of the file at the end of the script and,
	 * with fpm, when the response was ended).
	 *
	 * @return array{out:string,code:int,log:string,probes:array<string,array{day:string,free:int,version:int}>}
	 */
	private function request(string $file, array $options): array
	{
		$state = $file . '.state';
		$log = $file . '.log';
		@unlink($state);
		@unlink($log);
		$options += ['db' => $file, 'journal' => 'wal', 'now' => self::NOW, 'state' => $state, 'log' => $log];
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
		Rebuild::afterCount($store, new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
		Assert::assertStringContainsString('Kizami: rebuild check failed', (string)@file_get_contents($log), 'a failing check is logged, not thrown');
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
		$result = $this->request($file, ['kind' => 'count', 'fpm' => true]);
		Assert::assertSame("PAGE\nFINISH\n", $result['out'], 'the page is complete before the response is ended');
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
