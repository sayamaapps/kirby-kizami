<?php
/** CLI only: cron cleanup or a consistent SQLite snapshot; never copies the daily secret. */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
require_once __DIR__ . '/src/Secret.php';
require_once __DIR__ . '/src/Store.php';
try {
	// `--retention-months=N` must carry the value of kizami.retentionMonths
	// when the site sets it: this job has no Kirby options of its own.
	$retention = Kizami\Store::RETENTION_MONTHS;
	$args = [];
	foreach (array_slice($argv, 1) as $arg) {
		if (str_starts_with($arg, '--retention-months=')) {
			$retention = Kizami\Store::validRetentionMonths(substr($arg, strlen('--retention-months=')))
				?? throw new RuntimeException('--retention-months must be a whole number from 1 to ' . Kizami\Store::RETENTION_MONTHS_MAX);
		} else {
			$args[] = $arg;
		}
	}
	$action = $args[0] ?? '';
	$directory = $args[1] ?? '';
	$db = rtrim($directory, '/') . '/' . Kizami\Store::FILE;
	if (!in_array($action, ['compact', 'snapshot'], true) || !is_file($db)) {
		throw new RuntimeException('Usage: php maintenance.php [--retention-months=N] compact STORAGE/KIZAMI or snapshot STORAGE/KIZAMI NEW-FILE.sqlite; an existing database is required');
	}
	$store = new Kizami\Store($db, null, [], $retention);
	$deleted = $store->compact();
	if ($action === 'snapshot') {
		if (empty($args[2])) {
			throw new RuntimeException('New snapshot path missing');
		}
		$store->snapshot($args[2]);
	}
	// Also retire a stale daily key: a site without counted visits would
	// keep yesterday's key otherwise. Removed, not replaced: this job runs as
	// another user than the web server, so a key it created would belong to
	// the wrong owner and the server could not read it.
	$day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
	$keyFile = rtrim($directory, '/') . '/' . Kizami\Secret::FILE;
	$keyNote = 'no daily key';
	if (is_file($keyFile)) {
		$current = strtok((string)@file_get_contents($keyFile), "\n") === $day;
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
