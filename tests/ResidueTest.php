<?php
/**
 * Deleted events must not stay behind as bytes: not in the database file, not
 * in its -wal file, not in a snapshot. Disposable SQLite fixtures only.
 *   php tests/ResidueTest.php
 */
require_once __DIR__ . '/../src/Store.php';
use Kizami\Store;

const MARKER = 'EXPIRED_PRIVATE_DETAIL_';
$root = sys_get_temp_dir() . '/kizami-residue-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$checks = 0;
function same(string $label, mixed $expected, mixed $actual): void {
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    ++$checks;
}
/** All bytes the database occupies on disk: main file and, if present, -wal. */
function diskBytes(string $file): string {
    return (string) @file_get_contents($file) . (string) @file_get_contents($file . '-wal');
}
function hasMarker(string $file): bool {
    return str_contains(diskBytes($file), MARKER);
}
function insertOld(SQLite3 $db, int $rows): void {
    $stmt = $db->prepare("INSERT INTO kizami_custom_events (created_at, event, detail, path, session) VALUES ('2010-01-01 10:00:00', 'download', :detail, '/', 'old')");
    for ($i = 0; $i < $rows; $i++) {
        $stmt->bindValue(':detail', MARKER . $i, SQLITE3_TEXT);
        $stmt->execute()->finalize();
    }
}
function scalar(string $file, string $sql): mixed {
    $db = new SQLite3($file);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}
/** Insert rows and delete them again on a connection WITHOUT secure_delete: what older versions did. */
function leaveResidue(string $file): void {
    $raw = new SQLite3($file);
    $raw->exec('PRAGMA secure_delete = OFF');
    insertOld($raw, 200);
    $raw->exec('DELETE FROM kizami_custom_events');
    $raw->close();
}

$failure = null;
try {
    foreach (['wal', 'delete'] as $mode) {
        $dir = "$root/$mode";
        mkdir($dir, 0700);
        $file = $dir . '/' . Store::FILE;

        // The Store creates the schema; its connection is the one that deletes.
        $store = new Store($file, $mode);
        same("$mode: secure_delete is on for a Store connection", 1,
            (int) (new ReflectionProperty($store, 'db'))->getValue($store)->querySingle('PRAGMA secure_delete'));
        $raw = new SQLite3($file);
        insertOld($raw, 200);
        $raw->close();
        same("$mode: counter-check, the marker is in the file before compact", true, hasMarker($file));

        $store->compact();
        $snapshot = "$dir/snapshot.sqlite";
        $store->snapshot($snapshot);
        unset($store); // the last connection closes: the WAL is checkpointed

        same("$mode: the old events are gone", 0, scalar($file, 'SELECT COUNT(*) FROM kizami_custom_events'));
        same("$mode: marker not in the active database file", false, hasMarker($file));
        same("$mode: marker not in the snapshot", false, hasMarker($snapshot));
        same("$mode: no free pages after compact", 0, scalar($file, 'PRAGMA freelist_count'));
        same("$mode: no free pages in the snapshot", 0, scalar($snapshot, 'PRAGMA freelist_count'));
        same("$mode: snapshot is intact", 'ok', scalar($snapshot, 'PRAGMA integrity_check'));
    }

    // A source that still has free pages (deleted without secure_delete,
    // never compacted) must not hand them on to the snapshot.
    $dir = "$root/source";
    mkdir($dir, 0700);
    $file = $dir . '/' . Store::FILE;
    $store = new Store($file, 'delete');
    leaveResidue($file);
    same('snapshot source: counter-check, free pages and marker present', [true, true],
        [scalar($file, 'PRAGMA freelist_count') > 0, hasMarker($file)]);
    $store->snapshot("$dir/copy.sqlite");
    same('snapshot of a source with free pages: marker not carried over', false, hasMarker("$dir/copy.sqlite"));
    same('snapshot of a source with free pages: no free pages', 0, scalar("$dir/copy.sqlite", 'PRAGMA freelist_count'));
    unset($store);

    // Residue from before this version: bytes still in the file. The first
    // compact() rebuilds the file once.
    foreach (['wal', 'delete'] as $mode) {
        $dir = "$root/legacy-$mode";
        mkdir($dir, 0700);
        $file = $dir . '/' . Store::FILE;
        $store = new Store($file, $mode);
        unset($store);
        leaveResidue($file);
        same("legacy $mode: counter-check, the marker survived the plain delete", true, hasMarker($file));
        same("legacy $mode: not yet rebuilt", 0, scalar($file, 'PRAGMA user_version'));

        $store = new Store($file, $mode);
        $store->compact();
        unset($store);
        same("legacy $mode: marker gone after compact", false, hasMarker($file));
        same("legacy $mode: rebuild recorded", 1, scalar($file, 'PRAGMA user_version'));
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
        insertOld($raw, 3);
        for ($i = 0; $i < 50; $i++) {
            $raw->exec("INSERT INTO kizami_custom_events (created_at, event, detail, path, session) VALUES ('2999-01-01 10:00:00', 'download', 'recent', '/', 'new')");
        }
        $raw->close();
        same("later $mode: counter-check, the marker is in the file", true, hasMarker($file));
        $store->compact();
        unset($store);
        same("later $mode: the 3 old events are deleted, the rest stays", 50, scalar($file, 'SELECT COUNT(*) FROM kizami_custom_events'));
        same("later $mode: no free page was involved", 0, scalar($file, 'PRAGMA freelist_count'));
        same("later $mode: a deletion after the first rebuild leaves no marker", false, hasMarker($file));
    }

    // Free pages are rebuilt away by the next explicit compact, also after
    // a compactDaily() that ran in a visitor's request and could not do it.
    $dir = "$root/daily";
    mkdir($dir, 0700);
    $file = $dir . '/' . Store::FILE;
    $store = new Store($file, 'delete');
    $store->compact();
    $raw = new SQLite3($file);
    insertOld($raw, 200);
    $raw->close();
    $store->compactDaily();
    same('daily: counter-check, compactDaily() leaves free pages behind', true, scalar($file, 'PRAGMA freelist_count') > 0);
    same('daily: a deleting roll-up clears the rebuild mark', 0, scalar($file, 'PRAGMA user_version'));
    $store->compact();
    unset($store);
    same('daily: the next compact() clears the free pages and the marker', [0, false], [scalar($file, 'PRAGMA freelist_count'), hasMarker($file)]);

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
    same('delete mode: the second compact() does not rebuild or write again', $counter, file_get_contents($dir . '/delete.sqlite', false, null, 24, 4));
    unset($store);
    same('fresh file: marked after the first compact', 1, scalar($file, 'PRAGMA user_version'));
    same('fresh file: recent events survive the rebuild', 1, scalar($file, "SELECT COUNT(*) FROM kizami_events WHERE path='/keep'"));
} catch (Throwable $e) {
    $failure = $e->getMessage();
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($files as $item) {
    if ($item->isDir()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); }
}
rmdir($root);
if ($failure !== null) {
    fwrite(STDERR, "Residue: $failure ($checks checks passed before)\n");
    exit(1);
}
echo "Residue: all $checks checks passed\n";
