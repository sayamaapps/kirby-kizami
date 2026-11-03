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
 *
 * The commit alone does not touch the events table, though. So the probe also
 * inserts a test row inside a savepoint and rolls back to it: a damaged table
 * or index ("database disk image is malformed") is reported, and no row, no
 * sequence value and no page stays behind.
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

	/** Zeroes the header of the root page of a table or an index: what a torn write leaves, a b-tree SQLite no longer recognises. */
	private static function destroyRootPage(string $file, string $name): void
	{
		$db = new SQLite3($file, SQLITE3_OPEN_READONLY);
		$root = (int)$db->querySingle("SELECT rootpage FROM sqlite_master WHERE name = '$name'");
		$pageSize = (int)$db->querySingle('PRAGMA page_size');
		$db->close();
		Assert::assertGreaterThan(1, $root, "counter-check, $name has a root page of its own");
		$handle = fopen($file, 'r+b');
		fseek($handle, ($root - 1) * $pageSize);
		fwrite($handle, str_repeat("\0", 8));
		fclose($handle);
	}

	/** Writes $rows events straight into the file (one transaction, no Store), with enough variety to give every index several pages. */
	private static function fillEvents(string $file, int $rows): void
	{
		$db = new SQLite3($file);
		$db->exec('BEGIN');
		for ($i = 0; $i < $rows; $i++) {
			$db->exec("INSERT INTO kizami_events (type, action, path, session, target) VALUES ('page', 'view', '/page-" . ($i % 40) . "', 'session-$i', 'target-" . ($i % 7) . "')");
		}
		$db->exec('COMMIT');
		$db->close();
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

	/** @return array<string,array{string,string}> */
	public static function damage(): array
	{
		$cases = [];
		foreach (['wal', 'delete'] as $mode) {
			foreach (['idx_kizami_events_image', 'idx_kizami_events_created_at', 'kizami_events'] as $object) {
				$cases["$mode, $object"] = [$mode, $object];
			}
		}
		return $cases;
	}

	/**
	 * A database whose events table or index is damaged: a real write fails with
	 * "database disk image is malformed", so the probe must not say "healthy".
	 * It did, once it only rewrote the first page.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('damage')]
	public function testDamagedEventsTableOrIndexIsReported(string $mode, string $object): void
	{
		$file = "$this->root/$mode.sqlite";
		$store = $this->store($mode);
		self::fillEvents($file, 300);
		Assert::assertSame(null, $store->writeProbe(), "$mode: counter-check, the probe passes before the damage");
		unset($store);
		gc_collect_cycles();
		Assert::assertSame(false, file_exists("$file-wal"), "$mode: counter-check, the last connection is closed, so the damage is in the file the next connection reads");
		self::destroyRootPage($file, $object);

		$store = new Store($file, $mode);
		$error = $store->writeProbe();
		Assert::assertIsString($error, "$mode, $object: damage in the events table or an index is not healthy");
		Assert::assertStringContainsString('malformed', $error, "$mode, $object: the reason of SQLite is returned");
		Assert::assertStringNotContainsString('cannot rollback', $error, "$mode, $object: the rollback does not replace it");
		try {
			$store->write(['type' => 'page', 'action' => 'view', 'path' => '/after', 'session' => 'visitor']);
			$failed = false;
		} catch (RuntimeException) {
			$failed = true;
		}
		Assert::assertTrue($failed, "$mode, $object: counter-check, a real write fails on this database, the probe agrees with it");
		$other = new SQLite3($file);
		$other->busyTimeout(0);
		Assert::assertTrue($other->exec('BEGIN IMMEDIATE'), "$mode, $object: the failed probe ended its transaction and holds no lock");
		$other->exec('ROLLBACK');
		$other->close();
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

	/**
	 * A test row that needs a new page (the last page of the table or of an
	 * index is full) must not leave that page behind, neither in the file nor
	 * on the free list.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testProbeLeavesNoPageBehindAtAPageBoundary(string $mode): void
	{
		$file = "$this->root/$mode.sqlite";
		$store = new Store($file, $mode);
		$raw = new SQLite3($file);
		$raw->busyTimeout(5000);
		$boundaries = 0;
		for ($i = 0; $i < 200; $i++) {
			$raw->exec("INSERT INTO kizami_events (type, action, path, session, target) VALUES ('page', 'view', '/" . str_repeat('p', 30 + $i % 53) . "', 'session-$i', 'target-" . ($i % 7) . "')");
			// Would the test row need a new page right now?
			$pages = $raw->querySingle('PRAGMA page_count');
			$raw->exec('BEGIN');
			$raw->exec("INSERT INTO kizami_events (type, action) VALUES ('page', 'view')");
			$boundaries += $raw->querySingle('PRAGMA page_count') > $pages ? 1 : 0;
			$raw->exec('ROLLBACK');
			$before = self::content($file);
			Assert::assertSame(null, $store->writeProbe());
			Assert::assertSame($before, self::content($file), "$mode: row $i, no row, no page, no sequence value changed");
		}
		Assert::assertGreaterThan(0, $boundaries, "$mode: counter-check, the fixture reached a point where the test row needs a new page");
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
		Assert::assertSame('kizami_events=1', $before['sequence'], "$mode: counter-check, kizami_events counts with AUTOINCREMENT and the test row would raise it to 2");
		for ($i = 0; $i < 3; $i++) {
			Assert::assertSame(null, $store->writeProbe());
		}
		Assert::assertSame($before, self::content($file), "$mode: no row, no page, no user version, no sequence changed");
		$store->write(['type' => 'page', 'action' => 'view', 'path' => '/next', 'session' => 'visitor']);
		$after = self::content($file);
		Assert::assertCount(2, $after['kizami_events'], "$mode: the database is writable after the probe, no transaction stays open");
		Assert::assertSame(2, $after['kizami_events'][1]['id'], "$mode: the next real event gets the next id, the probe used none");
		Assert::assertSame('kizami_events=2', $after['sequence'], "$mode: the sequence moved by the real event only");
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('modes')]
	public function testProbeOnAnEmptyTableLeavesTheSequenceEmpty(string $mode): void
	{
		$file = "$this->root/$mode.sqlite";
		$store = new Store($file, $mode);
		$before = self::content($file);
		Assert::assertSame([], $before['kizami_events'], "$mode: counter-check, no event yet");
		Assert::assertSame(null, $before['sequence'], "$mode: counter-check, no sequence value yet");
		Assert::assertSame(null, $store->writeProbe());
		Assert::assertSame($before, self::content($file), "$mode: the test row did not create a sequence value");
		$store->write(['type' => 'page', 'action' => 'view', 'path' => '/first', 'session' => 'visitor']);
		Assert::assertSame(1, self::content($file)['kizami_events'][0]['id'], "$mode: the first real event gets id 1");
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
