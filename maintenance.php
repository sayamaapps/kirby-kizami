<?php
/** CLI only: cron cleanup or a consistent SQLite snapshot; never copies the daily secret. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/src/Secret.php';
require_once __DIR__ . '/src/Store.php';
try {
    $action = $argv[1] ?? '';
    $directory = $argv[2] ?? '';
    $db = rtrim($directory, '/') . '/' . Kizami\Store::FILE;
    if (!in_array($action, ['compact', 'snapshot'], true) || !is_file($db)) {
        throw new RuntimeException('Usage: php maintenance.php compact STORAGE/KIZAMI or snapshot STORAGE/KIZAMI NEW-FILE.sqlite; an existing database is required');
    }
    $store = new Kizami\Store($db);
    $deleted = $store->compact();
    if ($action === 'snapshot') {
        if (empty($argv[3])) { throw new RuntimeException('New snapshot path missing'); }
        $store->snapshot($argv[3]);
    }
    // Also retire a stale daily key: a site without counted visits would
    // keep yesterday's key otherwise.
    $day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $keyFile = rtrim($directory, '/') . '/' . Kizami\Secret::FILE;
    $stale = is_file($keyFile) && strtok((string) file_get_contents($keyFile), "\n") !== $day;
    if (!Kizami\Secret::rotate($directory, $day)) {
        throw new RuntimeException('Stale daily key could not be replaced (secret.txt not writable)');
    }
    $keyNote = $stale ? 'stale daily key replaced' : 'daily key current';
    echo "Kizami: {$deleted} old single events rolled up into daily totals and removed; {$keyNote}; {$action} succeeded.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kizami: ' . $e->getMessage() . "\n");
    exit(1);
}
