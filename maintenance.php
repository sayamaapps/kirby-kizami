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
    // keep yesterday's key otherwise. Removed, not replaced: this job runs as
    // another user than the web server, so a key it created would belong to
    // the wrong owner and the server could not read it.
    $day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $keyFile = rtrim($directory, '/') . '/' . Kizami\Secret::FILE;
    $keyNote = 'no daily key';
    if (is_file($keyFile)) {
        $current = strtok((string) @file_get_contents($keyFile), "\n") === $day;
        if (!Kizami\Secret::expire($directory, $day)) {
            throw new RuntimeException('Stale daily key could not be removed');
        }
        $keyNote = $current ? 'daily key current' : 'stale daily key removed';
    }
    echo "Kizami: {$deleted} old single events rolled up into daily totals and removed; {$keyNote}; {$action} succeeded.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kizami: ' . $e->getMessage() . "\n");
    exit(1);
}
