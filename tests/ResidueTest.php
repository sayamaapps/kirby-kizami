<?php

/**
 * Deleted events must not stay behind as bytes: not in the database file, not
 * in its -wal file, not in a snapshot. Disposable SQLite fixtures only.
 *   php tests/ResidueTest.php
 */
use PHPUnit\Framework\Assert;
/** All bytes the database occupies on disk: main file and, if present, -wal. */
/** Insert rows and delete them again on a connection WITHOUT secure_delete: what older versions did. */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class ResidueTest extends TestCase
{
	private const MARKER = 'EXPIRED_PRIVATE_DETAIL_';

	private static function diskBytes(string $file): string
	{
		return (string)@file_get_contents($file) . (string)@file_get_contents($file . '-wal');
	}

	private static function hasMarker(string $file): bool
	{
		return str_contains(self::diskBytes($file), self::MARKER);
	}

	private static function insertOld(SQLite3 $db, int $rows): void
	{
		$stmt = $db->prepare("INSERT INTO kizami_custom_events (created_at, event, detail, path, session) VALUES ('2010-01-01 10:00:00', 'download', :detail, '/', 'old')");
		for ($i = 0; $i < $rows; $i++) {
			$stmt->bindValue(':detail', self::MARKER . $i, SQLITE3_TEXT);
			$stmt->execute()->finalize();
		}
	}

	private static function leaveResidue(string $file): void
	{
		$raw = new SQLite3($file);
		$raw->exec('PRAGMA secure_delete = OFF');
		self::insertOld($raw, 200);
		$raw->exec('DELETE FROM kizami_custom_events');
		$raw->close();
	}

	private static function scalar(string $file, string $sql): mixed
	{
		$db = new SQLite3($file);
		$value = $db->querySingle($sql);
		$db->close();
		return $value;
	}

	public function testSuite(): void
	{
		$root = sys_get_temp_dir() . '/kizami-residue-' . bin2hex(random_bytes(8));
		mkdir($root, 0700);
		$failure = null;
		try {
			foreach (['wal', 'delete'] as $mode) {
				$dir = "$root/$mode";
				mkdir($dir, 0700);
				$file = $dir . '/' . Store::FILE;

				// The Store creates the schema; its connection is the one that deletes.
				$store = new Store($file, $mode);
				Assert::assertSame(1, (int)(new ReflectionProperty($store, 'db'))->getValue($store)->querySingle('PRAGMA secure_delete'), "$mode: secure_delete is on for a Store connection");
				$raw = new SQLite3($file);
				self::insertOld($raw, 200);
				$raw->close();
				Assert::assertSame(true, self::hasMarker($file), "$mode: counter-check, the marker is in the file before compact");

				$store->compact();
				$snapshot = "$dir/snapshot.sqlite";
				$store->snapshot($snapshot);
				unset($store); // the last connection closes: the WAL is checkpointed

				Assert::assertSame(0, self::scalar($file, 'SELECT COUNT(*) FROM kizami_custom_events'), "$mode: the old events are gone");
				Assert::assertSame(false, self::hasMarker($file), "$mode: marker not in the active database file");
				Assert::assertSame(false, self::hasMarker($snapshot), "$mode: marker not in the snapshot");
				Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), "$mode: no free pages after compact");
				Assert::assertSame(0, self::scalar($snapshot, 'PRAGMA freelist_count'), "$mode: no free pages in the snapshot");
				Assert::assertSame('ok', self::scalar($snapshot, 'PRAGMA integrity_check'), "$mode: snapshot is intact");
			}

			// A source that still has free pages (deleted without secure_delete,
			// never compacted) must not hand them on to the snapshot.
			$dir = "$root/source";
			mkdir($dir, 0700);
			$file = $dir . '/' . Store::FILE;
			$store = new Store($file, 'delete');
			self::leaveResidue($file);
			Assert::assertSame([true, true], [self::scalar($file, 'PRAGMA freelist_count') > 0, self::hasMarker($file)], 'snapshot source: counter-check, free pages and marker present');
			$store->snapshot("$dir/copy.sqlite");
			Assert::assertSame(false, self::hasMarker("$dir/copy.sqlite"), 'snapshot of a source with free pages: marker not carried over');
			Assert::assertSame(0, self::scalar("$dir/copy.sqlite", 'PRAGMA freelist_count'), 'snapshot of a source with free pages: no free pages');
			unset($store);

			// Residue from before this version: bytes still in the file. The first
			// compact() rebuilds the file once.
			foreach (['wal', 'delete'] as $mode) {
				$dir = "$root/legacy-$mode";
				mkdir($dir, 0700);
				$file = $dir . '/' . Store::FILE;
				$store = new Store($file, $mode);
				unset($store);
				self::leaveResidue($file);
				Assert::assertSame(true, self::hasMarker($file), "legacy $mode: counter-check, the marker survived the plain delete");
				Assert::assertSame(0, self::scalar($file, 'PRAGMA user_version'), "legacy $mode: not yet rebuilt");

				$store = new Store($file, $mode);
				$store->compact();
				unset($store);
				Assert::assertSame(false, self::hasMarker($file), "legacy $mode: marker gone after compact");
				Assert::assertSame(1, self::scalar($file, 'PRAGMA user_version'), "legacy $mode: rebuild recorded");
			}

			// Later deletions: the first rebuild has run and no page becomes free,
			// yet a few rows deleted from a page that stays in use must not leave
			// bytes either (secure_delete, and on old SQLite builds the rebuild that
			// follows every deleting roll-up).
			foreach (['wal', 'delete'] as $mode) {
				$dir = "$root/later-$mode";
				mkdir($dir, 0700);
				$file = $dir . '/' . Store::FILE;
				$store = new Store($file, $mode);
				$store->compact();
				$raw = new SQLite3($file);
				self::insertOld($raw, 3);
				for ($i = 0; $i < 50; $i++) {
					$raw->exec("INSERT INTO kizami_custom_events (created_at, event, detail, path, session) VALUES ('2999-01-01 10:00:00', 'download', 'recent', '/', 'new')");
				}
				$raw->close();
				Assert::assertSame(true, self::hasMarker($file), "later $mode: counter-check, the marker is in the file");
				$store->compact();
				unset($store);
				Assert::assertSame(50, self::scalar($file, 'SELECT COUNT(*) FROM kizami_custom_events'), "later $mode: the 3 old events are deleted, the rest stays");
				Assert::assertSame(0, self::scalar($file, 'PRAGMA freelist_count'), "later $mode: no free page was involved");
				Assert::assertSame(false, self::hasMarker($file), "later $mode: a deletion after the first rebuild leaves no marker");
			}

			// Free pages are rebuilt away by the next explicit compact, also after
			// a compactDaily() that ran in a visitor's request and could not do it.
			$dir = "$root/daily";
			mkdir($dir, 0700);
			$file = $dir . '/' . Store::FILE;
			$store = new Store($file, 'delete');
			$store->compact();
			$raw = new SQLite3($file);
			self::insertOld($raw, 200);
			$raw->close();
			$store->compactDaily();
			Assert::assertSame(true, self::scalar($file, 'PRAGMA freelist_count') > 0, 'daily: counter-check, compactDaily() leaves free pages behind');
			Assert::assertSame(0, self::scalar($file, 'PRAGMA user_version'), 'daily: a deleting roll-up clears the rebuild mark');
			$store->compact();
			unset($store);
			Assert::assertSame([0, false], [self::scalar($file, 'PRAGMA freelist_count'), self::hasMarker($file)], 'daily: the next compact() clears the free pages and the marker');

			// A fresh file gets the mark too (cheap), and live data survives.
			$dir = "$root/fresh";
			mkdir($dir, 0700);
			$file = $dir . '/' . Store::FILE;
			$store = new Store($file);
			$store->write(['type' => 'page', 'action' => 'view', 'path' => '/keep', 'session' => 's']);
			$store->compact();
			// Rollback-journal mode: bytes 24-27 of the header count every change to
			// the file. A second compact() finds nothing to rebuild and writes nothing.
			$store = new Store($dir . '/delete.sqlite', 'delete');
			$store->compact();
			$counter = file_get_contents($dir . '/delete.sqlite', false, null, 24, 4);
			$store->compact();
			Assert::assertSame($counter, file_get_contents($dir . '/delete.sqlite', false, null, 24, 4), 'delete mode: the second compact() does not rebuild or write again');
			unset($store);
			Assert::assertSame(1, self::scalar($file, 'PRAGMA user_version'), 'fresh file: marked after the first compact');
			Assert::assertSame(1, self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE path='/keep'"), 'fresh file: recent events survive the rebuild');
		} catch (Throwable $e) {
			$failure = $e->getMessage();
		}
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}
		rmdir($root);
		if ($failure !== null) {
			throw $e;
		}
	}
}
