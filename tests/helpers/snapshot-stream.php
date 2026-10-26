<?php
/**
 * Child process of tests/ReportTest.php, not a test itself (run.sh only runs
 * tests/*Test.php). Boots Kirby like the test does, answers GET /k/snapshot
 * and sends the response the way production does (send()), counting and
 * hashing the output in an output buffer callback instead of keeping it.
 * The parent starts it with a small memory_limit to show that the copy is
 * streamed: a database larger than the limit must still come through.
 *
 *   php -d memory_limit=24M tests/helpers/snapshot-stream.php <Kirby root> <storage root>
 *
 * Prints one JSON line: code, bytes, hash, length and sha256 as announced in
 * the headers, the first bytes (head), returned (what send() returned) and
 * memory_get_peak_usage().
 */
$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';

[, $root, $storage] = $argv + [null, null, null];
if (!is_string($root) || !is_string($storage)) {
	fwrite(STDERR, "usage: snapshot-stream.php <Kirby root> <storage root>\n");
	exit(2);
}

$app = new Kirby\Cms\App([
	'roots' => [
		'index' => $root . '/public', 'base' => $root, 'content' => $root . '/content',
		'site' => $root . '/site', 'storage' => $storage, 'accounts' => $root . '/site/accounts',
	],
	'request' => [
		'method' => 'GET',
		'url' => 'http://example.invalid/k/snapshot',
		'auth' => 'Basic ' . base64_encode('snapshot@example.invalid:report-password-very-long-123'),
	],
	'options' => [
		'url' => 'http://example.invalid',
		'kizami.active' => true, 'kizami.snapshot' => true, 'kizami.reportWithoutHttps' => true,
	],
]);

$response = Sayamaapps\Kizami\SnapshotService::respond($app);

$bytes = 0;
$head = '';
$context = hash_init('sha256');
ob_start(function (string $chunk) use (&$bytes, &$head, $context): string {
	if (strlen($head) < 15) {
		$head .= substr($chunk, 0, 15 - strlen($head));
	}
	hash_update($context, $chunk);
	$bytes += strlen($chunk);
	return '';
}, 65536);
$returned = $response->send();
ob_end_flush();

echo json_encode([
	'code' => $response->code(),
	'bytes' => $bytes,
	'hash' => hash_final($context),
	'length' => $response->headers()['Content-Length'] ?? null,
	'sha256' => $response->headers()['X-Kizami-Sha256'] ?? null,
	'head' => $head,
	'returned' => $returned,
	'peak' => memory_get_peak_usage(),
]), "\n";
