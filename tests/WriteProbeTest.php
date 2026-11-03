<?php

/**
 * Store::writeProbe(): a database that cannot be written must not pass itself
 * off as healthy, and the reason must come back as a string, whatever state
 * the failed transaction was left in. Disposable SQLite fixtures only.
 *   php tests/WriteProbeTest.php
 *
 * What the probe must do is reach the physical commit path. In WAL mode a
 * rolled-back test row never gets there: it stays in the page cache, so a full
 * file system still reported "healthy" while real writes failed with
 * SQLITE_FULL. A full file system is hard to reproduce portably (a
 * CI runner cannot be filled), so the commit is proven by its footprint: one
 * frame appended to the -wal file, or the change counter in the header of the
 * database file. A failing write is simulated with a lock, a read-only
 * connection and a connection that aborts the transaction by itself.
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Analysis;
use Sayamaapps\Kizami\I18n;
use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Store;

/**
 * A connection on which SQLite has already ended the transaction when a
 * statement fails, as it does for SQLITE_FULL or an I/O error during a
 * commit: the error reaches the caller, and a following ROLLBACK is refused
 * with "cannot rollback - no transaction is active".
 */
final class AbortingConnection extends SQLite3
{
	public function exec(string $query): bool
	{
		$verb = strtoupper((string)strtok(ltrim($query), " \t\n"));
		if ($verb === 'BEGIN' || $verb === 'ROLLBACK') {
			return parent::exec($query);
		}
		parent::exec('ROLLBACK');
		// SQLite3Exception exists since PHP 8.3; before, SQLite3 threw a plain Exception.
		$class = class_exists('SQLite3Exception') ? 'SQLite3Exception' : 'Exception';
		throw new $class('database or disk is full', 13);
	}
}

#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class WriteProbeTest extends TestCase
{
	private string $root = '';

	/** The change counter in the header of a rollback-journal database: it rises with every commit. */
	private static function changeCounter(string $file): int
	{
		clearstatcache(true, $file);
		return unpack('N', (string)file_get_contents($file, false, null, 24, 4))[1];
	}

	private static function connection(Store $store): SQLite3
	{
		return (new ReflectionProperty($store, 'db'))->getValue($store);
	}

	/** Every table with all its rows, the page counts and the user version: what a probe must leave as it was. */
	private static function content(string $file): array
	{
		$db = new SQLite3($file, SQLITE3_OPEN_READONLY);
		$state = [];
		$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
		while ($row = $tables->fetchArray(SQLITE3_NUM)) {
			$rows = $db->query('SELECT * FROM ' . $row[0] . ' ORDER BY 1');
			while ($r = $rows->fetchArray(SQLITE3_ASSOC)) {
				$state[$row[0]][] = $r;
			}
			$state[$row[0]] ??= [];
		}
		foreach (['user_version', 'page_count', 'freelist_count'] as $pragma) {
			$state[$pragma] = $db->querySingle("PRAGMA $pragma");
		}
		$state['sequence'] = $db->querySingle("SELECT group_concat(name || '=' || seq) FROM sqlite_sequence");
		$db->close();
		return $state;
	}

	private static function replaceConnection(Store $store, SQLite3 $db): void
	{
		(new ReflectionProperty($store, 'db'))->setValue($store, $db);
	}

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/kizami-probe-' . bin2hex(random_bytes(8));
		mkdir($this->root, 0700);
	}

	private function store(string $mode): Store
	{
		$store = new Store("$this->root/$mode.sqlite", $mode);
		$store->write(['type' => 'page', 'action' => 'view', 'path' => '/start', 'session' => 'visitor']);
		return $store;
	}

	protected function tearDown(): void
	{
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}
		rmdir($this->root);
	}

	/** @return array<string,array{string}> */
	public static function modes(): array
	{
		return ['wal' => ['wal'], 'delete' => ['delete']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testAbortedTransactionKeepsTheOriginalError(string $mode): void
	{
		$store = $this->store($mode);
		$aborting = new AbortingConnection("$this->root/$mode.sqlite");
		$aborting->enableExceptions(true);
		self::replaceConnection($store, $aborting);
		Assert::assertSame('database or disk is full', $store->writeProbe(), "$mode: the error of the failed write comes back although SQLite already ended the transaction");
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testDashboardShowsTheProbeResult(string $mode): void
	{
		$store = $this->store($mode);
		$names = new Names([], [], []);
		$data = Analysis::data($store, $names, 7, []);
		Assert::assertSame(null, $data['technical']['writeError'], "$mode: healthy, no error in the dashboard data");
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		$html = ob_get_clean();
		Assert::assertStringContainsString(I18n::t('technical.storageOk'), $html, "$mode: dashboard says storage is fine");

		self::connection($store)->busyTimeout(0);
		$other = new SQLite3("$this->root/$mode.sqlite");
		$other->exec('BEGIN IMMEDIATE');
		$data = Analysis::data($store, $names, 7, []);
		$other->exec('ROLLBACK');
		$other->close();
		Assert::assertSame('database is locked', $data['technical']['writeError'], "$mode: the probe error reaches the dashboard data as it did");
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		$html = ob_get_clean();
		Assert::assertStringContainsString(I18n::t('technical.storageProblem'), $html, "$mode: dashboard reports a storage problem");
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testLockedDatabaseReturnsTheError(string $mode): void
	{
		$store = $this->store($mode);
		self::connection($store)->busyTimeout(0);
		$other = new SQLite3("$this->root/$mode.sqlite");
		$other->exec('BEGIN IMMEDIATE');
		$error = $store->writeProbe();
		Assert::assertSame('database is locked', $error, "$mode: BEGIN IMMEDIATE failed, so there is nothing to roll back, and the reason stays");
		$other->exec('ROLLBACK');
		$other->close();
		Assert::assertSame(null, $store->writeProbe(), "$mode: healthy again once the lock is gone");
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testProbeCommitsARealWrite(string $mode): void
	{
		$store = $this->store($mode);
		$file = "$this->root/$mode.sqlite";
		Assert::assertSame(null, $store->writeProbe(), "$mode: a healthy database reports null");
		if ($mode === 'wal') {
			// The writer connection is open, so the -wal file holds the frames of
			// the schema and the first row. A rolled-back test row appends none.
			$wal = $file . '-wal';
			clearstatcache(true, $wal);
			$before = filesize($wal);
			Assert::assertSame(null, $store->writeProbe());
			clearstatcache(true, $wal);
			Assert::assertGreaterThan($before, filesize($wal), 'wal: the probe commits, one more frame lands in the -wal file');
		} else {
			$before = self::changeCounter($file);
			Assert::assertSame(null, $store->writeProbe());
			Assert::assertGreaterThan($before, self::changeCounter($file), 'delete: the probe commits, the change counter of the file rises');
			Assert::assertSame(false, file_exists($file . '-journal'), 'delete: no journal is left behind');
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testProbeLeavesNothingBehind(string $mode): void
	{
		$store = $this->store($mode);
		$file = "$this->root/$mode.sqlite";
		// A user version that is not the default: the probe must hand it back.
		$raw = new SQLite3($file);
		$raw->exec('PRAGMA user_version = 7');
		$raw->close();
		$before = self::content($file);
		Assert::assertSame(7, $before['user_version'], "$mode: counter-check, the user version is set");
		Assert::assertCount(1, $before['kizami_events'], "$mode: counter-check, the fixture holds one event");
		for ($i = 0; $i < 3; $i++) {
			Assert::assertSame(null, $store->writeProbe());
		}
		Assert::assertSame($before, self::content($file), "$mode: no row, no page, no user version, no sequence changed");
		$store->write(['type' => 'page', 'action' => 'view', 'path' => '/next', 'session' => 'visitor']);
		Assert::assertCount(2, self::content($file)['kizami_events'], "$mode: the database is writable after the probe, no transaction stays open");
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testReadOnlyConnectionReturnsTheError(string $mode): void
	{
		$store = $this->store($mode);
		$readOnly = new SQLite3("$this->root/$mode.sqlite", SQLITE3_OPEN_READONLY);
		$readOnly->enableExceptions(true);
		self::replaceConnection($store, $readOnly);
		$error = $store->writeProbe();
		Assert::assertIsString($error, "$mode: a database that cannot be written is not healthy");
		Assert::assertStringContainsString('readonly', $error, "$mode: the reason of SQLite is returned");
		Assert::assertStringNotContainsString('cannot rollback', $error, "$mode: the rollback does not replace it");
	}
}
