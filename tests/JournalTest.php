<?php

/**
 * kizami.journal: WAL (default) or the classic rollback journal.
 *   php tests/JournalTest.php
 *
 * Why: on some shared hosts (SQLite 3.7.17) WAL mode left a database
 * malformed after earlier "disk I/O error"s. With `delete` the
 * file stays one file without -wal/-shm. Every opener has to respect the
 * mode; CLI tools without Kirby options keep the mode the file already has.
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class JournalTest extends TestCase
{
	private static function modus(string $file): string
	{
		$db = new SQLite3($file);
		$m = strtolower((string)$db->querySingle('PRAGMA journal_mode'));
		$db->close();
		return $m;
	}

	private static function pruefe(bool $ok, string $was): void
	{
		Assert::assertTrue($ok, $was);
	}

	public function testSuite(): void
	{
		$dir = sys_get_temp_dir() . '/kizami-journal-' . bin2hex(random_bytes(4));
		mkdir($dir);
		try {
			self::pruefe(Store::journal(null) === 'wal', 'null → wal');
			self::pruefe(Store::journal('DELETE ') === 'delete', 'Groß/Leerzeichen → delete');
			self::pruefe(Store::journal('truncate') === 'wal', 'unbekannt → wal');
			self::pruefe(Store::journalOfFile("$dir/fehlt.sqlite") === 'wal', 'fehlende Datei → wal');

			// Default: new file in WAL
			$a = "$dir/a.sqlite";
			$s = new Store($a);
			unset($s);
			self::pruefe(self::modus($a) === 'wal', 'neue Datei ohne Angabe: WAL');
			self::pruefe(Store::journalOfFile($a) === 'wal', 'Kopf erkennt WAL');

			// Switch to delete
			$s = new Store($a, 'delete');
			unset($s);
			self::pruefe(self::modus($a) === 'delete', 'delete stellt bestehende WAL-Datei um');
			self::pruefe(Store::journalOfFile($a) === 'delete', 'Kopf erkennt delete');
			// Linux entfernt -wal/-shm; Apples SQLite lässt leere Reste stehen (persistentes WAL).
			self::pruefe(!is_file("$a-wal") || filesize("$a-wal") === 0, 'keine -wal mit Inhalt nach delete');

			// CLI (null) keeps delete
			$s = new Store($a);
			unset($s);
			self::pruefe(self::modus($a) === 'delete', 'ohne Angabe bleibt delete (CLI kippt nicht zurück)');

			// explicit wal switches back
			$s = new Store($a, 'wal');
			unset($s);
			self::pruefe(self::modus($a) === 'wal', 'wal stellt zurück');

			// a new file with delete
			$b = "$dir/b.sqlite";
			$s = new Store($b, 'delete');
			unset($s);
			self::pruefe(self::modus($b) === 'delete', 'neue Datei mit delete');
		} finally {
			foreach (glob("$dir/*") ?: [] as $f) {
				@unlink($f);
			}
			@rmdir($dir);
		}
	}
}
