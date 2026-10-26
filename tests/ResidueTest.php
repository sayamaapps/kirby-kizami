<?php

/**
 * Deleted events must not stay behind as bytes: not in the database file, not
 * in its -wal file, not in a snapshot. Disposable SQLite fixtures only.
 *   php tests/ResidueTest.php
 */
use PHPUnit\Framework\Assert;
/** All bytes the database occupies on disk: main file and, if present, -wal. */
/** Insert rows and delete them again on a connection WITHOUT secure_delete: what older versions did. */

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Store;

#[CoversNothing]
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

	private static function same(string $label, mixed $expected, mixed $actual): void
	{
		global $checks;
		if ($expected !== $actual) {
			throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
		}
		++$checks;
		Assert::assertTrue(true);
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
		global $checks;
		$root = sys_get_temp_dir() . '/kizami-residue-' . bin2hex(random_bytes(8));
		mkdir($root, 0700);
		$checks = 0;
		$failure = null;
		try {
			foreach (['wal', 'delete'] as $mode) {
				$dir = "$root/$mode";
				mkdir($dir, 0700);
				$file = $dir . '/' . Store::FILE;

				// The Store creates the schema; its connection is the one that deletes.
				$store = new Store($file, $mode);
				self::same(
					"$mode: secure_delete is on for a Store connection",
					1,
					(int)(new ReflectionProperty($store, 'db'))->getValue($store)->querySingle('PRAGMA secure_delete')
				);
				$raw = new SQLite3($file);
				self::insertOld($raw, 200);
				$raw->close();
				self::same("$mode: counter-check, the marker is in the file before compact", true, self::hasMarker($file));

				$store->compact();
				$snapshot = "$dir/snapshot.sqlite";
				$store->snapshot($snapshot);
				unset($store); // the last connection closes: the WAL is checkpointed

				self::same("$mode: the old events are gone", 0, self::scalar($file, 'SELECT COUNT(*) FROM kizami_custom_events'));
				self::same("$mode: marker not in the active database file", false, self::hasMarker($file));
				self::same("$mode: marker not in the snapshot", false, self::hasMarker($snapshot));
				self::same("$mode: no free pages after compact", 0, self::scalar($file, 'PRAGMA freelist_count'));
				self::same("$mode: no free pages in the snapshot", 0, self::scalar($snapshot, 'PRAGMA freelist_count'));
				self::same("$mode: snapshot is intact", 'ok', self::scalar($snapshot, 'PRAGMA integrity_check'));
			}

			// A source that still has free pages (deleted without secure_delete,
			// never compacted) must not hand them on to the snapshot.
			$dir = "$root/source";
			mkdir($dir, 0700);
			$file = $dir . '/' . Store::FILE;
			$store = new Store($file, 'delete');
			self::leaveResidue($file);
			self::same(
				'snapshot source: counter-check, free pages and marker present',
				[true, true],
				[self::scalar($file, 'PRAGMA freelist_count') > 0, self::hasMarker($file)]
			);
			$store->snapshot("$dir/copy.sqlite");
			self::same('snapshot of a source with free pages: marker not carried over', false, self::hasMarker("$dir/copy.sqlite"));
			self::same('snapshot of a source with free pages: no free pages', 0, self::scalar("$dir/copy.sqlite", 'PRAGMA freelist_count'));
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
				self::same("legacy $mode: counter-check, the marker survived the plain delete", true, self::hasMarker($file));
				self::same("legacy $mode: not yet rebuilt", 0, self::scalar($file, 'PRAGMA user_version'));

				$store = new Store($file, $mode);
				$store->compact();
				unset($store);
				self::same("legacy $mode: marker gone after compact", false, self::hasMarker($file));
				self::same("legacy $mode: rebuild recorded", 1, self::scalar($file, 'PRAGMA user_version'));
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
				self::same("later $mode: counter-check, the marker is in the file", true, self::hasMarker($file));
				$store->compact();
				unset($store);
				self::same("later $mode: the 3 old events are deleted, the rest stays", 50, self::scalar($file, 'SELECT COUNT(*) FROM kizami_custom_events'));
				self::same("later $mode: no free page was involved", 0, self::scalar($file, 'PRAGMA freelist_count'));
				self::same("later $mode: a deletion after the first rebuild leaves no marker", false, self::hasMarker($file));
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
			self::same('daily: counter-check, compactDaily() leaves free pages behind', true, self::scalar($file, 'PRAGMA freelist_count') > 0);
			self::same('daily: a deleting roll-up clears the rebuild mark', 0, self::scalar($file, 'PRAGMA user_version'));
			$store->compact();
			unset($store);
			self::same('daily: the next compact() clears the free pages and the marker', [0, false], [self::scalar($file, 'PRAGMA freelist_count'), self::hasMarker($file)]);

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
			self::same('delete mode: the second compact() does not rebuild or write again', $counter, file_get_contents($dir . '/delete.sqlite', false, null, 24, 4));
			unset($store);
			self::same('fresh file: marked after the first compact', 1, self::scalar($file, 'PRAGMA user_version'));
			self::same('fresh file: recent events survive the rebuild', 1, self::scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE path='/keep'"));
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
