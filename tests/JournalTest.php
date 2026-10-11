<?php
/**
 * kizami.journal: WAL (default) or the classic rollback journal.
 *   php tests/JournalTest.php
 *
 * Why: on 11.10.2026 mustergasthaus's database (Shared-Host, SQLite 3.7.17) was found
 * malformed in WAL mode after earlier "disk I/O error"s. With `delete` the
 * file stays one file without -wal/-shm. Every opener has to respect the
 * mode; CLI tools without Kirby options keep the mode the file already has.
 */

require_once __DIR__ . '/../src/Store.php';

use Kizami\Store;

$passed = 0;
$failures = [];
function pruefe(bool $ok, string $was): void
{
    global $passed, $failures;
    $ok ? $passed++ : $failures[] = $was;
}
function modus(string $file): string
{
    $db = new SQLite3($file);
    $m = strtolower((string) $db->querySingle('PRAGMA journal_mode'));
    $db->close();
    return $m;
}

$dir = sys_get_temp_dir() . '/kizami-journal-' . bin2hex(random_bytes(4));
mkdir($dir);
try {
    pruefe(Store::journal(null) === 'wal', 'null → wal');
    pruefe(Store::journal('DELETE ') === 'delete', 'Groß/Leerzeichen → delete');
    pruefe(Store::journal('truncate') === 'wal', 'unbekannt → wal');
    pruefe(Store::journalOfFile("$dir/fehlt.sqlite") === 'wal', 'fehlende Datei → wal');

    // Default: new file in WAL
    $a = "$dir/a.sqlite";
    $s = new Store($a); unset($s);
    pruefe(modus($a) === 'wal', 'neue Datei ohne Angabe: WAL');
    pruefe(Store::journalOfFile($a) === 'wal', 'Kopf erkennt WAL');

    // Switch to delete
    $s = new Store($a, 'delete'); unset($s);
    pruefe(modus($a) === 'delete', 'delete stellt bestehende WAL-Datei um');
    pruefe(Store::journalOfFile($a) === 'delete', 'Kopf erkennt delete');
    // Linux entfernt -wal/-shm; Apples SQLite lässt leere Reste stehen (persistentes WAL).
    pruefe(!is_file("$a-wal") || filesize("$a-wal") === 0, 'keine -wal mit Inhalt nach delete');

    // CLI (null) keeps delete
    $s = new Store($a); unset($s);
    pruefe(modus($a) === 'delete', 'ohne Angabe bleibt delete (CLI kippt nicht zurück)');

    // explicit wal switches back
    $s = new Store($a, 'wal'); unset($s);
    pruefe(modus($a) === 'wal', 'wal stellt zurück');

    // a new file with delete
    $b = "$dir/b.sqlite";
    $s = new Store($b, 'delete'); unset($s);
    pruefe(modus($b) === 'delete', 'neue Datei mit delete');
} finally {
    foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
}

echo "JournalTest: $passed bestanden" . ($failures ? ', ' . count($failures) . " fehlgeschlagen:\n- " . implode("\n- ", $failures) : '') . "\n";
exit($failures ? 1 : 0);
