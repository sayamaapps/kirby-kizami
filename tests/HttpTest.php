<?php

/** Real Kirby HTTP regressions; all mutable data lives in a disposable directory.
 * Run: ddev exec php tests/HttpTest.php
 */
/**
 * The router builds a new App per request and so re-reads config.php every
 * time. $source is appended as raw PHP array entries — for closures, which
 * var_export() can't write.
 */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Sayamaapps\Kizami\Runtime::class)]
#[RunTestsInSeparateProcesses]
final class HttpTest extends TestCase
{
	private static function requestHttp(string $path, array $headers = [], array|null $post = null): array
	{
		global $port;
		// Only the last User-Agent is sent (the default one or the last passed
		// one): with two headers, which one the server keeps differs between PHP
		// builds (the built-in server of Ubuntu's PHP 8.3.6 keeps the first).
		$headers = ['User-Agent: Mozilla/5.0 Firefox/130.0', ...$headers];
		$agents = preg_grep('/^User-Agent:/i', $headers);
		$headers = array_values(array_diff_key($headers, array_slice($agents, 0, -1, true)));
		$context = stream_context_create(['http' => [
			'method' => $post === null ? 'GET' : 'POST',
			'content' => $post === null ? '' : http_build_query($post),
			'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5,
			'header' => implode("\r\n", [...($post === null ? [] : ['Content-Type: application/x-www-form-urlencoded']), ...$headers]),
		]]);
		$body = @file_get_contents("http://127.0.0.1:$port$path", false, $context);
		$responseHeaders = $http_response_header ?? [];
		preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
		return [(int)($match[1] ?? 0), (string)$body, $responseHeaders];
	}

	private static function rowCount(): int
	{
		global $tmp;
		$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
		$n = $db->querySingle('SELECT COUNT(*) FROM kizami_events');
		$db->close();
		return $n;
	}

	public function testSuite(): void
	{
		global $port, $tmp;
		$repo = dirname(__DIR__);
		$tmp = sys_get_temp_dir() . '/kizami-http-' . bin2hex(random_bytes(8));
		$process = null;
		try {
			if (!is_file($repo . '/vendor/autoload.php')) {
				Assert::fail('Composer dependencies missing; run composer install first');
			}
			foreach (['public', 'site/config', 'site/plugins', 'site/templates', 'site/accounts', 'content/home', 'content/error', 'storage'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($repo, $tmp . '/site/plugins/kizami');
			file_put_contents($tmp . '/content/home/home.txt', "Title: Test\n");
			file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
			file_put_contents($tmp . '/site/templates/home.php', '<?php if (isset($_GET["snippet"])) { snippet("kizami/events"); return; } if (isset($_GET["campaign-snippet"])) { snippet("kizami/campaign"); return; } if (isset($_GET["campaign-url"])) { echo Sayamaapps\\Kizami\\Runtime::campaignUrl(kirby(), (string)$_GET["campaign-url"]); return; } echo isset($_GET["links"]) ? $page->kizamiRedirectLink("call", "tel:+498000000", "header") : "Fixture OK";');
			file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
			// A page that a route of the site's own also serves under a short code (/EEAQ77).
			mkdir($tmp . '/content/book');
			file_put_contents($tmp . '/content/book/book.txt', "Title: Book\n");
			file_put_contents($tmp . '/site/templates/book.php', '<?php snippet("kizami/events"); echo "Book";');
			// Dashboard login: Kirby accounts. The role kizami-dashboard exists while the module is active.
			$accounts = new Kirby\Cms\App([
				'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site', 'storage' => $tmp . '/storage', 'accounts' => $tmp . '/site/accounts'],
				// Kirby reads blueprints once per process: the report role has to be
				// registered by this first App, the later Apps of this file need it too.
				'options' => ['url' => 'http://127.0.0.1', 'kizami.active' => true, 'kizami.report' => true],
			]);
			$accounts->impersonate('kirby');
			$accounts->users()->create(['email' => 'admin@example.invalid', 'role' => 'admin', 'password' => 'admin-password-very-long-123']);
			$accounts->users()->create(['email' => 'dashboard@example.invalid', 'role' => 'kizami-dashboard', 'password' => 'dashboard-password-very-long-123']);
			// A file with a hash, for the K8 check below (extraCss only delivers .css).
			file_put_contents($tmp . '/site/config/secrets.php', '<?php return ' . var_export([
				'user' => 'fixture', 'hash' => password_hash('fixture-password', PASSWORD_DEFAULT),
			], true) . ';');
			$config = [
				'debug' => false, 'cache' => false,
				'kizami.active' => true,
				// The test server speaks plain HTTP; the dashboard, report and
				// snapshot refuse that unless this is set (checked explicitly below).
				'kizami.reportWithoutHttps' => true,
				'kizami.dwellTime' => true,
				'kizami.images' => ['yard' => 'Yard', 'garden' => 'Garden'],
				'kizami.contactTargets' => ['call'],
				'kizami.positionNames' => ['header' => 'Header area'],
				'kizami.redirects' => ['call' => 'tel:+498000000'],
				'kizami.events' => [
					'download' => ['label' => ['de' => 'Download', 'en' => 'Download'], 'detail' => true],
					'video_play' => ['label' => ['de' => 'Video gestartet', 'en' => 'Video played']],
					'shortcode_access' => ['label' => 'Short code', 'detail' => true],
				],
				// K8: extraCss may only include .css files. If it points (by mistake
				// or with intent) at the access file itself, its content (the bcrypt
				// hash) must NOT end up in the delivered CSS.
				'kizami.extraCss' => 'site/config/secrets.php',
			];
			self::writeConfig($config);
			$router = '<?php $_SERVER["SCRIPT_NAME"] = "/index.php"; require ' . var_export($repo . '/vendor/autoload.php', true) . '; '
				. '$base = dirname(__DIR__); echo (new Kirby\\Cms\\App(["roots" => ['
				. '"index" => __DIR__, "base" => $base, "site" => $base . "/site", '
				. '"content" => $base . "/content", "storage" => $base . "/storage"]]))->render();';
			file_put_contents($tmp . '/public/index.php', $router);
			$socket = stream_socket_server('tcp://127.0.0.1:0');
			$address = stream_socket_get_name($socket, false);
			$port = (int)substr(strrchr($address, ':'), 1);
			fclose($socket);
			$process = proc_open(
				[PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'opcache.enable=0', '-S', "127.0.0.1:$port", '-t', $tmp . '/public', $tmp . '/public/index.php'],
				[0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']],
				$pipes
			);
			if (!is_resource($process)) {
				Assert::fail('Test server does not start');
			}
			fclose($pipes[0]);
			$ready = false;
			for ($i = 0; $i < 50; $i++) {
				usleep(100000);
				$probe = self::requestHttp('/', ['DNT: 1']);
				if ($probe[0] === 200 && $probe[1] === 'Fixture OK') {
					$ready = true;
					break;
				}
			}
			Assert::assertTrue($ready, 'Test server not reachable; ' . file_get_contents($tmp . '/server.log'));
			// Report route: without kizami.report the real 404, despite the active module.
			[$status, $body] = self::requestHttp('/k/report', ['Authorization: Basic ' . base64_encode('dashboard@example.invalid:dashboard-password-very-long-123')]);
			Assert::assertTrue($status === 404 && $body === 'Fixture 404', 'Report route off by default');
			Assert::assertTrue(self::requestHttp('/?utm_source=test&utm_medium=email&utm_campaign=autumn')[0] === 200, 'Scalar UTM');
			foreach (['utm_source[]=test', 'utm_medium[]=test', 'utm_campaign[a][b]=test'] as $query) {
				Assert::assertTrue(self::requestHttp('/?' . $query)[0] === 200, 'Array must not break the page: ' . $query);
			}
			$before = self::rowCount();
			foreach (['DNT: 1', 'Sec-GPC: 1'] as $optout) {
				Assert::assertTrue(self::requestHttp('/?utm_source[]=test', [$optout])[0] === 200, 'Opt-out + invalid UTM');
			}
			Assert::assertTrue(self::rowCount() === $before, 'Opt-out records no rows');
			Assert::assertTrue(self::requestHttp('/', ['Referer: http://127.0.0.1:' . $port . '/contact'])[0] === 200, 'Internal referrer');
			$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			Assert::assertTrue($db->querySingle('SELECT referrer FROM kizami_events ORDER BY id DESC LIMIT 1') === '(internal)', 'Internal referrer kept separate');
			Assert::assertTrue(self::requestHttp('/does-not-exist')[0] === 404, '404 stays 404');
			// Own handle: $db is open at this point and reused by the archive check below.
			$dbNotFound = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			Assert::assertTrue($dbNotFound->querySingle("SELECT COUNT(*) FROM kizami_events WHERE type='notfound' AND path='/does-not-exist'") === 1, 'Missing page recorded');
			$dbNotFound->close();
			$db->exec("INSERT INTO kizami_events (type, action, created_at) VALUES ('page','view','2010-01-01 00:00:00')");
			$db->exec('DELETE FROM kizami_maintenance');
			$db->close();
			self::requestHttp('/');
			$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			Assert::assertTrue($db->querySingle("SELECT COUNT(*) FROM kizami_events WHERE created_at < '2011'") === 0, 'HTTP request compacts without the dashboard');
			Assert::assertTrue($db->querySingle("SELECT views FROM kizami_days WHERE day='2010-01-01'") === 1, 'HTTP compaction keeps old views as a daily total');
			$db->close();
			[$status, , $headers] = self::requestHttp('/call?from[]=test');
			Assert::assertTrue($status === 302 && in_array('Location: tel:+498000000', $headers, true), 'Array from keeps the phone redirect');
			Assert::assertTrue(self::requestHttp('/?links=1', ['DNT: 1'])[1] === '/call?from=%2F&position=header', 'Link helper passes position and home page');
			self::requestHttp('/call?from=%2F&position=header');
			$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			Assert::assertTrue($db->querySingle('SELECT position FROM kizami_events ORDER BY id DESC LIMIT 1') === 'header', 'Valid position recorded');
			self::requestHttp('/call?from=%2F&position[]=header');
			Assert::assertTrue($db->querySingle('SELECT position FROM kizami_events ORDER BY id DESC LIMIT 1') === '', 'Array position ignored');
			$db->close();

			// Link filter (Kizami 1.0.0): redirects only count with a confirmed
			// click (Sec-Fetch-User: ?1) or after a page view by the same day
			// identifier. The redirect always happens; discarded hits land in the
			// daily counter kizami_discarded.
			$discarded = function (): int {
				global $tmp;
				$d = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
				$n = (int)$d->querySingle('SELECT COALESCE(SUM(hits), 0) FROM kizami_discarded');
				$d->close();
				return $n;
			};
			$stranger = 'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';
			$navigation = ['Sec-Fetch-Site: same-origin', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document'];
			[$rows, $gone] = [self::rowCount(), $discarded()];
			[$status, , $headers] = self::requestHttp('/call?from=%2F', [$stranger]);
			Assert::assertTrue($status === 302 && in_array('Location: tel:+498000000', $headers, true), 'Link filter: without Sec-Fetch and without page view still 302');
			Assert::assertTrue(in_array('X-Robots-Tag: noindex, nofollow', $headers, true), 'Redirect with X-Robots-Tag noindex, nofollow');
			Assert::assertTrue(self::rowCount() === $rows && $discarded() === $gone + 1, 'Link filter: without Sec-Fetch and without page view not counted, daily counter +1');
			Assert::assertTrue(self::requestHttp('/call?from=%2F', [$stranger, ...$navigation])[0] === 302, 'Link filter: navigate without Sec-Fetch-User 302');
			Assert::assertTrue(self::rowCount() === $rows && $discarded() === $gone + 2, 'Link filter: navigate without Sec-Fetch-User and without page view not counted');
			Assert::assertTrue(self::requestHttp('/call?from=%2F', [$stranger, ...$navigation, 'Sec-Fetch-User: ?1'])[0] === 302, 'Link filter: Sec-Fetch-User ?1 302');
			Assert::assertTrue(self::rowCount() === $rows + 1 && $discarded() === $gone + 2, 'Link filter: Sec-Fetch-User ?1 counted');
			Assert::assertTrue(self::requestHttp('/call?from=%2F', [$stranger, 'User-Agent: Googlebot'])[0] === 302 && $discarded() === $gone + 2, 'Link filter: bot neither counted nor in the daily counter');
			Assert::assertTrue(self::requestHttp('/call?from=%2F', [$stranger, 'DNT: 1'])[0] === 302 && $discarded() === $gone + 2 && self::rowCount() === $rows + 1, 'Link filter: DNT neither counted nor in the daily counter');
			$safari = 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
			self::requestHttp('/', [$safari]);
			[$rows, $gone] = [self::rowCount(), $discarded()];
			Assert::assertTrue(self::requestHttp('/call?from=%2F', [$safari])[0] === 302 && self::rowCount() === $rows + 1 && $discarded() === $gone, 'Link filter: without Sec-Fetch, but page view today → counted');
			// Safari sends Sec-Fetch-Site/-Mode/-Dest, but never Sec-Fetch-User (WebKit bug 247697).
			Assert::assertTrue(self::requestHttp('/call?from=%2F', [$safari, ...$navigation])[0] === 302 && self::rowCount() === $rows + 2 && $discarded() === $gone, 'Link filter: Safari navigation without Sec-Fetch-User after page view → counted');
			$origin = 'Origin: http://127.0.0.1:' . $port;
			$before = self::rowCount();
			$post = ['image' => 'yard', 'path' => '/'];
			foreach ([[], ['Origin: https://stranger.example'], [$origin, 'Sec-Fetch-Site: cross-site'], [$origin, 'DNT: 1'], [$origin, 'Sec-GPC: 1'], [$origin, 'User-Agent: Googlebot']] as $h) {
				Assert::assertTrue(self::requestHttp('/k/image', $h, $post)[0] === 204, 'Rejected event without revealing internals');
			}
			foreach ([['image' => 'unknown', 'path' => '/'], ['image' => ['yard'], 'path' => '/'], ['image' => 'yard', 'path' => ['/']], ['image' => 'yard', 'path' => '/?secret=test'], ['image' => 'yard', 'path' => '/missing'], ['image' => 'yard', 'path' => '//example.org']] as $bad) {
				Assert::assertTrue(self::requestHttp('/k/image', [$origin], $bad)[0] === 204, 'Invalid image data silently rejected');
			}
			Assert::assertTrue(self::rowCount() === $before, 'Rejected events write nothing');
			Assert::assertTrue(self::requestHttp('/k/image', [$origin, 'Sec-Fetch-Site: same-origin'], $post)[0] === 204, 'Own image event accepted');
			self::requestHttp('/k/image', [$origin], $post);
			Assert::assertTrue(self::rowCount() === $before + 1, 'Reopening is deduplicated');
			self::requestHttp('/k/image', [$origin], ['image' => 'garden', 'path' => '/']);
			Assert::assertTrue(self::rowCount() === $before + 2, 'Another image counted separately');

			// /k/duration: the same origin check as /k/image, its own seconds validation.
			// Own counter ($beforeDuration), so the $before references of the image
			// checks below stay unchanged.
			$beforeDuration = self::rowCount();
			foreach ([[], ['Origin: https://stranger.example'], [$origin, 'Sec-Fetch-Site: cross-site']] as $h) {
				Assert::assertTrue(self::requestHttp('/k/duration', $h, ['seconds' => 5, 'path' => '/'])[0] === 204, 'Foreign origin without revealing internals');
			}
			foreach ([
				['seconds' => '0', 'path' => '/'], ['seconds' => '-1', 'path' => '/'], ['seconds' => 'abc', 'path' => '/'],
				['seconds' => '1.5', 'path' => '/'], ['seconds' => ['5'], 'path' => '/'], ['seconds' => '5', 'path' => '/missing'],
				['seconds' => '5', 'path' => ['/']], ['seconds' => '5', 'path' => '/?secret=1'], ['seconds' => '5', 'path' => '//example.org'],
			] as $bad) {
				Assert::assertTrue(self::requestHttp('/k/duration', [$origin], $bad)[0] === 204, 'Invalid dwell-time data silently rejected: ' . json_encode($bad));
			}
			Assert::assertTrue(self::rowCount() === $beforeDuration, 'Rejected dwell-time reports write nothing');
			Assert::assertTrue(self::requestHttp('/k/duration', [$origin], ['seconds' => '42', 'path' => '/'])[0] === 204, 'Own dwell-time report accepted');
			Assert::assertTrue(self::rowCount() === $beforeDuration + 1, 'Dwell-time row written');
			$dbDuration = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			Assert::assertTrue($dbDuration->querySingle("SELECT type||'|'||action||'|'||path||'|'||seconds FROM kizami_events ORDER BY id DESC LIMIT 1") === 'duration|visible|/|42', 'Dwell-time row has the right fields');
			Assert::assertTrue(self::requestHttp('/k/duration', [$origin], ['seconds' => '99999', 'path' => '/'])[0] === 204, 'Oversized value accepted (capped)');
			Assert::assertTrue((int)$dbDuration->querySingle('SELECT seconds FROM kizami_events ORDER BY id DESC LIMIT 1') === 1800, 'Seconds capped at 1800 instead of discarded');
			$dbDuration->close();
			$config['kizami.dwellTime'] = false;
			self::writeConfig($config);
			Assert::assertTrue(self::requestHttp('/k/duration', [$origin], ['seconds' => '5', 'path' => '/'])[0] === 404, 'Switched-off option returns 404');
			$config['kizami.dwellTime'] = true;
			self::writeConfig($config);

			// /k/event (1.4.0): the origin, path and opt-out rules of /k/image, only
			// configured keys, the detail cleaned or dropped, a size cap, always 204.
			$customRows = function (): int {
				global $tmp;
				$d = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
				$n = (int)$d->querySingle('SELECT COUNT(*) FROM kizami_custom_events');
				$d->close();
				return $n;
			};
			$lastCustom = function (): string {
				global $tmp;
				$d = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
				$row = (string)$d->querySingle("SELECT event || '|' || detail || '|' || path || '|' || (session <> '') FROM kizami_custom_events ORDER BY id DESC LIMIT 1");
				$d->close();
				return $row;
			};
			$beforeEvents = $customRows();
			$download = ['event' => 'download', 'path' => '/', 'detail' => 'menu.pdf'];
			foreach ([
				'no Origin' => [], 'foreign Origin' => ['Origin: https://stranger.example'], 'Origin with another port' => ['Origin: http://127.0.0.1:1'],
				'cross-site' => [$origin, 'Sec-Fetch-Site: cross-site'], 'same-site' => [$origin, 'Sec-Fetch-Site: same-site'],
				'DNT' => [$origin, 'DNT: 1'], 'GPC' => [$origin, 'Sec-GPC: 1'],
				'bot' => [$origin, 'User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1)'], 'empty user agent' => [$origin, 'User-Agent: '],
			] as $what => $h) {
				Assert::assertTrue(self::requestHttp('/k/event', $h, $download)[0] === 204, 'Action rejected without revealing it: ' . $what);
			}
			Assert::assertTrue($customRows() === $beforeEvents, 'Rejected actions (origin, Sec-Fetch-Site, DNT, GPC, bot, empty user agent) write nothing');
			foreach ([
				['event' => 'unknown', 'path' => '/'], ['event' => 'Download', 'path' => '/'], ['event' => "download\n", 'path' => '/'], ['event' => ['download'], 'path' => '/'],
				['path' => '/'], ['event' => 'download'], ['event' => 'download', 'path' => '/missing'], ['event' => 'download', 'path' => '/?secret=1'],
				['event' => 'download', 'path' => '//example.org'], ['event' => 'download', 'path' => ['/']], ['event' => 'download', 'path' => 'relative'],
			] as $bad) {
				Assert::assertTrue(self::requestHttp('/k/event', [$origin], $bad)[0] === 204, 'Invalid action silently rejected: ' . json_encode($bad));
			}
			Assert::assertTrue($customRows() === $beforeEvents, 'Unknown actions and missing or foreign pages write nothing');
			Assert::assertTrue(self::requestHttp('/k/event', [$origin, 'Sec-Fetch-Site: same-origin'], ['event' => 'download', 'path' => '/', 'detail' => 'https://cdn.example/menu.pdf?token=SECRET#p2']) [0] === 204, 'Own action accepted');
			Assert::assertTrue($lastCustom() === 'download|cdn.example/menu.pdf|/|1', 'Action stored with day identifier, the URL without query and fragment');
			self::requestHttp('/k/event', [$origin], ['event' => 'download', 'path' => '/', 'detail' => "Line\r\nbreak\t<b>" . str_repeat('x', 200)]);
			Assert::assertTrue($lastCustom() === 'download|Line break b' . str_repeat('x', 88) . '|/|1', 'Detail without control characters and angle brackets, at most 100 characters');
			self::requestHttp('/k/event', [$origin], ['event' => 'video_play', 'path' => '/', 'detail' => 'not allowed here']);
			Assert::assertTrue($lastCustom() === 'video_play||/|1', 'Detail dropped for an action without detail => true');
			self::requestHttp('/k/event', [$origin], ['event' => 'download', 'path' => '/', 'detail' => ['x']]);
			Assert::assertTrue($lastCustom() === 'download||/|1', 'A detail that is no string is dropped, the action counts');
			$counted = $customRows();
			Assert::assertTrue(self::requestHttp('/k/event', [$origin], ['event' => 'download', 'path' => '/', 'detail' => str_repeat('y', 2100)])[0] === 204, 'Oversized body answered with 204');
			Assert::assertTrue(self::requestHttp('/k/event?pad=' . str_repeat('y', 2100), [$origin], $download)[0] === 204, 'Oversized query string answered with 204');
			Assert::assertTrue($customRows() === $counted, 'Body or query over 2 KB writes nothing');
			Assert::assertTrue(self::requestHttp('/k/event', [$origin], ['event' => 'download', 'path' => '/', 'detail' => str_repeat('y', 1900)])[0] === 204 && $customRows() === $counted + 1, 'Counter-check: just under 2 KB is counted');
			Assert::assertTrue(self::requestHttp('/k/event', ['DNT: 1'])[0] === 404, 'GET /k/event is no route');
			Assert::assertTrue(self::rowCount() === $beforeDuration + 2, 'Actions do not write to kizami_events');

			// The browser half: a file of its own, embedded by the snippet, no inline script.
			[$status, $js, $headers] = self::requestHttp('/k/event.js');
			Assert::assertTrue($status === 200 && $js === file_get_contents($repo . '/views/event.js'), 'event.js delivered from the own server');
			Assert::assertTrue(preg_grep('#^Content-Type: text/javascript#i', $headers) !== [] && in_array('X-Content-Type-Options: nosniff', $headers, true), 'event.js as JavaScript, nosniff');
			Assert::assertTrue(preg_grep('#^Cache-Control: public, max-age=86400#i', $headers) !== [], 'event.js cached for one day');
			foreach (['sendBeacon', 'keepalive: true', "credentials: 'omit'", "'data-kizami-event'", "'data-kizami-detail'", 'window.kizami.event = ', "addEventListener('play'"] as $needle) {
				Assert::assertTrue(str_contains($js, $needle), 'event.js contains ' . $needle);
			}
			foreach (['localStorage', 'sessionStorage', 'document.cookie', 'indexedDB', 'eval(', 'new Function'] as $needle) {
				Assert::assertTrue(!str_contains($js, $needle), 'event.js without ' . $needle);
			}
			[$status, $tag] = self::requestHttp('/?snippet=1', ['DNT: 1']);
			Assert::assertTrue($status === 200 && preg_match('#^\s*<script src="http://127\.0\.0\.1:' . $port . '/k/event\.js\?v=' . substr(md5_file($repo . '/views/event.js'), 0, 8) . '" data-kizami-path="/" defer></script>\s*$#', $tag) === 1, 'Snippet: one external script tag with a content hash and the page path, no inline script: ' . $tag);

			// Routed pages: a route of the site's own serves the page "book" under
			// the short code /EEAQ77 as well. The snippet names the rendered page,
			// not the address; /k/event still only takes existing pages.
			// count/<key> counts an action from PHP ($page->kizamiEvent()).
			$routes = <<<'PHP'
        'routes' => [
            ['pattern' => 'EEAQ77', 'action' => function () { return page('book'); }],
            ['pattern' => 'count/(:any)', 'method' => 'GET|HEAD|POST', 'action' => function ($key) {
                return page('book')->kizamiEvent($key, get('detail'));
            }],
        ]
        PHP;
			self::writeConfig($config, $routes);
			$bookTag = '#^\s*<script src="http://127\.0\.0\.1:' . $port . '/k/event\.js\?v=[0-9a-f]{8}" data-kizami-path="/book" defer></script>\s*Book$#';
			foreach (['/EEAQ77', '/book'] as $address) {
				[$status, $html] = self::requestHttp($address, ['DNT: 1']);
				Assert::assertTrue($status === 200 && preg_match($bookTag, $html) === 1, 'Snippet names the rendered page /book at ' . $address . ': ' . $html);
			}
			$beforeRouted = $customRows();
			Assert::assertTrue(self::requestHttp('/k/event', [$origin], ['event' => 'download', 'path' => '/EEAQ77'])[0] === 204 && $customRows() === $beforeRouted, 'The short code itself is still no page for /k/event');
			Assert::assertTrue(self::requestHttp('/k/event', [$origin], ['event' => 'download', 'path' => '/book', 'detail' => 'book.pdf'])[0] === 204, 'Action with the path from the snippet accepted');
			Assert::assertTrue($customRows() === $beforeRouted + 1 && $lastCustom() === 'download|book.pdf|/book|1', 'Action with the path from the snippet stored');

			// Server side: same rules as /k/event minus Origin/Sec-Fetch-Site.
			$lastSessions = function (): array {
				global $tmp;
				$d = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
				$sessions = [$d->querySingle("SELECT session FROM kizami_events WHERE type='page' ORDER BY id DESC LIMIT 1"),
					$d->querySingle('SELECT session FROM kizami_custom_events ORDER BY id DESC LIMIT 1')];
				$d->close();
				return $sessions;
			};
			$beforeServer = $customRows();
			[$status, $html] = self::requestHttp('/count/shortcode_access?detail=' . rawurlencode('https://example.org/x?token=SECRET'));
			Assert::assertTrue($status === 200 && preg_match($bookTag, $html) === 1, 'kizamiEvent() returns the page, the route renders it: ' . $html);
			Assert::assertTrue($customRows() === $beforeServer + 1 && $lastCustom() === 'shortcode_access|example.org/x|/book|1', 'Server-side action stored, detail cleaned, path of the page');
			[$viewSession, $eventSession] = $lastSessions();
			Assert::assertTrue(is_string($eventSession) && strlen($eventSession) === 64 && $viewSession === $eventSession, 'Server-side action has the day identifier of the page view of the same request');
			self::requestHttp('/count/video_play?detail=not-allowed');
			Assert::assertTrue($customRows() === $beforeServer + 2 && $lastCustom() === 'video_play||/book|1', 'Server side: detail dropped without detail => true');
			Assert::assertTrue(self::requestHttp('/count/shortcode_access', [], ['detail' => 'form'])[0] === 200 && $lastCustom() === 'shortcode_access|form|/book|1', 'Server side: a POST request counts too, without Origin');
			$countedServer = $customRows();
			foreach ([
				'DNT' => ['/count/shortcode_access', ['DNT: 1']], 'GPC' => ['/count/shortcode_access', ['Sec-GPC: 1']],
				'bot' => ['/count/shortcode_access', ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1)']],
				'empty user agent' => ['/count/shortcode_access', ['User-Agent: ']],
				'HeadlessChrome' => ['/count/shortcode_access', ['User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36']],
				'unknown key' => ['/count/unknown', []], 'invalid key' => ['/count/Shortcode_Access', []],
			] as $what => [$address, $h]) {
				[$status, $html] = self::requestHttp($address, $h);
				Assert::assertTrue($status === 200 && str_ends_with($html, 'Book'), 'Server side, not counted, page still served: ' . $what);
			}
			$head = stream_context_create(['http' => ['method' => 'HEAD', 'ignore_errors' => true, 'header' => 'User-Agent: Mozilla/5.0 Firefox/130.0']]);
			@file_get_contents("http://127.0.0.1:$port/count/shortcode_access", false, $head);
			Assert::assertTrue(preg_match('/\s200\s/', $http_response_header[0] ?? '') === 1, 'Server side: HEAD reaches the route');
			Assert::assertTrue($customRows() === $countedServer, 'Server side: DNT, GPC, bots, empty user agent, HEAD and unknown keys write nothing');
			self::writeConfig(array_merge($config, ['kizami.active' => false]), $routes);
			Assert::assertTrue(str_ends_with(self::requestHttp('/count/shortcode_access')[1], 'Book') && $customRows() === $countedServer, 'Server side: Kizami off counts nothing, page still served');
			self::writeConfig(array_merge($config, ['kizami.events' => []]), $routes);
			Assert::assertTrue(str_ends_with(self::requestHttp('/count/shortcode_access')[1], 'Book') && $customRows() === $countedServer, 'Server side: no actions configured counts nothing');
			self::writeConfig($config);

			// Off: the routes hide, the snippet prints nothing.
			$beforeOff = $customRows();
			foreach ([['kizami.events' => []], ['kizami.events' => ['Bad Key' => ['label' => 'x']]], ['kizami.active' => false]] as $off) {
				self::writeConfig(array_merge($config, $off));
				Assert::assertTrue(self::requestHttp('/k/event', [$origin], $download)[0] === 404, 'Actions off: /k/event is a real 404 ' . json_encode($off));
				Assert::assertTrue(self::requestHttp('/k/event.js')[0] === 404, 'Actions off: /k/event.js is a real 404 ' . json_encode($off));
				Assert::assertTrue(trim(self::requestHttp('/?snippet=1', ['DNT: 1'])[1]) === '', 'Actions off: the snippet prints nothing ' . json_encode($off));
			}
			self::writeConfig($config);
			Assert::assertTrue($customRows() === $beforeOff, 'Switched off: nothing counted');

			$config['kizami.active'] = false;
			self::writeConfig($config);
			$beforeInactive = self::rowCount();
			Assert::assertTrue(self::requestHttp('/k/image', [$origin, 'User-Agent: Mozilla/5.0 Safari/600'], $post)[0] === 404, 'Deactivated endpoint stays hidden');
			Assert::assertTrue(self::rowCount() === $beforeInactive, 'Deactivated module counts no images');
			Assert::assertTrue(self::requestHttp('/k/duration', [$origin], ['seconds' => '5', 'path' => '/'])[0] === 404, 'kizami.active off also hides /k/duration despite its own option on');
			Assert::assertTrue(self::requestHttp('/call')[0] === 302, 'Switching off keeps the link working');
			$config['kizami.active'] = true;
			self::writeConfig($config);
			// Browsers only show a login dialog on 401 + challenge, not on 404.
			[$status, $html, $headers] = self::requestHttp('/k/dashboard');
			Assert::assertTrue($status === 401 && preg_grep('/^WWW-Authenticate: Basic realm="Kizami"/i', $headers) !== [], 'Dashboard without login asks for login');
			Assert::assertTrue(!str_contains($html, 'class="tiles"'), 'Dashboard without login has no content');
			Assert::assertTrue(self::requestHttp('/k/dashboard', ['Authorization: Basic ' . base64_encode('fixture:wrong')])[0] === 401, 'Wrong dashboard password asked again');
			Assert::assertTrue(self::requestHttp('/k/dashboard.css')[0] === 401, 'Dashboard CSS without login rejected');
			$auth = ['Authorization: Basic ' . base64_encode('dashboard@example.invalid:dashboard-password-very-long-123')];
			// Kirby keeps percent-encoding in the path; it must not be interpreted
			// as HTML afterwards when rendering.
			self::requestHttp('/contact%3Cscript%3Ealert(1)%3C/script%3E');
			// The dashboard retires a stale daily key; an anonymous request does not.
			$keyFile = $tmp . '/storage/kizami/secret.txt';
			file_put_contents($keyFile, "2010-01-01\nSTALE-SECRET-VALUE");
			self::requestHttp('/k/dashboard');
			Assert::assertTrue(file_get_contents($keyFile) === "2010-01-01\nSTALE-SECRET-VALUE", 'Dashboard without login leaves a stale key alone');
			self::requestHttp('/k/dashboard', $auth);
			Assert::assertTrue(file_get_contents($keyFile) === "2010-01-01\nSTALE-SECRET-VALUE", 'Dashboard with login leaves a stale daily key alone (GET writes nothing, IDM-9)');
			// A filtered request (DNT, bot) removes a stale key without counting and
			// without making a new one; a current key stays; a counted visit still
			// replaces it.
			$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
			$stale = "2010-01-01\nSTALE-SECRET-VALUE";
			$rowsBeforeFiltered = self::rowCount();
			foreach (['DNT' => 'DNT: 1', 'GPC' => 'Sec-GPC: 1', 'bot' => 'User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1)'] as $what => $header) {
				file_put_contents($keyFile, $stale);
				self::requestHttp('/', [$header]);
				Assert::assertTrue(!file_exists($keyFile), "A filtered visit ($what) removes a stale daily key");
				Assert::assertTrue(self::rowCount() === $rowsBeforeFiltered, "A filtered visit ($what) counts nothing");
			}
			$currentKey = $today . "\nCURRENT-SECRET-VALUE";
			file_put_contents($keyFile, $currentKey);
			self::requestHttp('/', ['DNT: 1']);
			self::requestHttp('/', ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1)']);
			Assert::assertTrue(file_get_contents($keyFile) === $currentKey, 'A filtered visit leaves a current daily key byte for byte');
			file_put_contents($keyFile, $stale);
			self::requestHttp('/');
			Assert::assertTrue(str_starts_with((string)file_get_contents($keyFile), $today . "\n") &&
				!str_contains((string)file_get_contents($keyFile), 'STALE-SECRET-VALUE') && self::rowCount() === $rowsBeforeFiltered + 1, 'A counted visit still replaces a stale daily key');
			[$status, $html] = self::requestHttp('/k/dashboard', $auth);
			Assert::assertTrue($status === 200 && str_contains($html, 'class="tiles"'), 'Dashboard with login');
			Assert::assertTrue(str_contains($html, 'name="viewport"'), 'Dashboard viewport');
			Assert::assertTrue(str_contains($html, 'Not counted: 2 link hits'), 'Dashboard shows discarded link hits');
			Assert::assertTrue(str_contains($html, 'Link clicks') && str_contains($html, 'Link clicks without a previous page visit') && str_contains($html, 'Trend') && str_contains($html, 'Long term') && str_contains($html, 'Time on site'), 'Dashboard sections');
			Assert::assertTrue(str_contains($html, '/contact%3Cscript%3Ealert(1)%3C/script%3E') && !str_contains($html, '<script'), 'Missing-page path cleaned and without script');
			Assert::assertTrue(!preg_match('/ style="/', $html) && !str_contains($html, '<script'), 'No inline styles, no script');
			Assert::assertTrue(str_contains($html, '<h2 id="events-title">Actions</h2>') && str_contains($html, '<tr><td>Download</td>') && str_contains($html, '<td>cdn.example/menu.pdf</td>'), 'Dashboard shows the actions and their details');
			// K9: months appear readable ("Jan 10"), no longer as raw ISO "2010-01".
			Assert::assertTrue(str_contains($html, 'Jan 10'), 'Dashboard shows archived months');
			// Language: English by default — no kizami.language, no locale.
			Assert::assertTrue(str_contains($html, '<html lang="en">') && str_contains($html, 'Statistics') && !str_contains($html, 'Besuche'), 'Dashboard English by default');
			preg_match('/<section class="long-term block".*?<\/section>/s', $html, $longTerm);
			[$filterStatus, $filterHtml] = self::requestHttp('/k/dashboard?days=7', $auth);
			Assert::assertTrue($filterStatus === 200 && isset($longTerm[0]) && str_contains($filterHtml, $longTerm[0]), 'Period filter does not change the long term');
			Assert::assertTrue(str_contains($filterHtml, 'aria-current="page">7 days'), 'Period selection marks 7 days');
			[$cssStatus, $cssBody] = self::requestHttp('/k/dashboard.css', $auth);
			Assert::assertTrue($cssStatus === 200, 'Dashboard CSS with login');
			Assert::assertTrue(!str_contains($cssBody, 'hash'), 'K8: extraCss only delivers .css, not a PHP file with a password hash');

			// Language from Kirby's locale option when kizami.language is not set.
			self::writeConfig($config + ['locale' => 'de_DE.utf8']);
			[$status, $html] = self::requestHttp('/k/dashboard', $auth);
			Assert::assertTrue($status === 200 && str_contains($html, '<html lang="de">') && str_contains($html, 'Besuche') && str_contains($html, 'Kennzahlen'), 'Dashboard German from locale de_DE.utf8');
			// kizami.language wins over the locale.
			self::writeConfig($config + ['locale' => 'de_DE.utf8', 'kizami.language' => 'en']);
			[$status, $html] = self::requestHttp('/k/dashboard', $auth);
			Assert::assertTrue($status === 200 && str_contains($html, '<html lang="en">') && !str_contains($html, 'Besuche'), 'kizami.language overrides the locale');
			self::writeConfig($config);

			// Dashboard over plain HTTP: refused before any challenge or check.
			$noHttps = ['kizami.reportWithoutHttps' => false] + $config;
			self::writeConfig($noHttps);
			foreach (['/k/dashboard', '/k/dashboard.css'] as $route) {
				[$status, $body, $headers] = self::requestHttp($route, $auth);
				Assert::assertTrue($status === 403 && $body === 'HTTPS only.', "$route with valid login over HTTP: 403 HTTPS only");
				Assert::assertTrue(preg_grep('/^WWW-Authenticate:/i', $headers) === [], "$route over HTTP sends no challenge");
				Assert::assertTrue(preg_grep('/^Cache-Control: no-store/i', $headers) !== [] && preg_grep('/^X-Robots-Tag: noindex/i', $headers) !== [], "$route refusal not cached or indexed");
				[$status, $body, $headers] = self::requestHttp($route);
				Assert::assertTrue($status === 403 && $body === 'HTTPS only.' && preg_grep('/^WWW-Authenticate:/i', $headers) === [], "$route without login over HTTP: 403, not 401");
				Assert::assertTrue(self::requestHttp($route, ['Authorization: Basic ' . base64_encode('nobody@example.invalid:wrong')])[0] === 403, "$route with wrong login over HTTP: 403");
			}
			// The escape hatch is the same option as for report and snapshot.
			self::writeConfig($config);
			Assert::assertTrue(self::requestHttp('/k/dashboard', $auth)[0] === 200 && self::requestHttp('/k/dashboard.css', $auth)[0] === 200, 'reportWithoutHttps lets the dashboard work over HTTP');
			// Module off: Kirby's real 404, also over HTTP (no hint that the module exists).
			self::writeConfig(['kizami.active' => false] + $noHttps);
			foreach (['/k/dashboard', '/k/dashboard.css'] as $route) {
				[$status, $body] = self::requestHttp($route, $auth);
				Assert::assertTrue($status === 404 && $body === 'Fixture 404', "$route with the module off stays a real 404 over HTTP");
			}
			self::writeConfig($config);

			// Kirby's own failed-login limit (auth.trials, default 10 per address and per account).
			$kirbyLog = $tmp . '/site/accounts/.logins';
			@unlink($kirbyLog);
			$wrongAuth = ['Authorization: Basic ' . base64_encode('fixture:wrong')];
			for ($i = 1; $i <= 15; $i++) {
				Assert::assertTrue(self::requestHttp('/k/dashboard')[0] === 401, "Request $i without credentials asks for login");
			}
			Assert::assertTrue(!is_file($kirbyLog), 'Requests without credentials count nothing');
			for ($i = 1; $i <= 9; $i++) {
				Assert::assertTrue(self::requestHttp('/k/dashboard', $wrongAuth)[0] === 401, "Wrong password $i: 401");
			}
			Assert::assertTrue(self::requestHttp('/k/dashboard', $auth)[0] === 200, 'Right password after 9 failures still works');
			Assert::assertTrue(self::requestHttp('/k/dashboard', $wrongAuth)[0] === 401, 'Wrong password 10: 401');
			[$status, , $headers] = self::requestHttp('/k/dashboard', $auth);
			Assert::assertTrue($status === 401 && preg_grep('/^WWW-Authenticate:/i', $headers) !== [], 'Right password after 10 failures: refused by Kirby\'s limit');
			[$status] = self::requestHttp('/k/dashboard.css', $auth);
			Assert::assertTrue($status === 401, 'The stylesheet is refused the same way');
			Assert::assertTrue(is_file($kirbyLog), 'Kirby writes its failed-login log to site/accounts/.logins');
			unlink($kirbyLog);
			Assert::assertTrue(self::requestHttp('/k/dashboard', $auth)[0] === 200, 'Without Kirby\'s log the right password works again');
			@unlink($kirbyLog);

			// Campaign tag: utm_campaign wins; kizami.campaignParam names one more
			// parameter, used only while utm_campaign is empty after cleaning.
			$lastCampaign = function (): string {
				global $tmp;
				$d = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
				$value = (string)$d->querySingle("SELECT utm_campaign FROM kizami_events WHERE type='page' ORDER BY id DESC LIMIT 1");
				$d->close();
				return $value;
			};
			self::writeConfig($config + ['kizami.campaignParam' => 'cam']);
			Assert::assertTrue(self::requestHttp('/?cam=social-tg-test')[0] === 200 && $lastCampaign() === 'social-tg-test', 'campaignParam: cam stored as utm_campaign');
			Assert::assertTrue(self::requestHttp('/?cam=social-tg-test&utm_campaign=autumn')[0] === 200 && $lastCampaign() === 'autumn', 'campaignParam: utm_campaign wins');
			Assert::assertTrue(self::requestHttp('/?cam[]=x')[0] === 200 && $lastCampaign() === '', 'campaignParam: array value stored empty, page 200');
			Assert::assertTrue(self::requestHttp('/?cam=' . str_repeat('a', 60))[0] === 200 && strlen($lastCampaign()) === 50, 'campaignParam: capped at 50 characters');
			Assert::assertTrue(self::requestHttp('/?cam=' . rawurlencode('<b>x y</b>'))[0] === 200 && $lastCampaign() === 'bxyb', 'campaignParam: cleaned like utm values');
			// The carry (2.1.0): script and snippet exist while the parameter is set;
			// Runtime::campaignUrl() tags links with the tag of the request.
			[$status, $js, $headers] = self::requestHttp('/k/campaign.js');
			Assert::assertTrue($status === 200 && $js === file_get_contents($repo . '/views/campaign.js'), 'campaign.js delivered from the own server');
			Assert::assertTrue(preg_grep('#^Content-Type: text/javascript#i', $headers) !== [] && in_array('X-Content-Type-Options: nosniff', $headers, true), 'campaign.js as JavaScript, nosniff');
			Assert::assertTrue(preg_grep('#^Cache-Control: public, max-age=86400#i', $headers) !== [], 'campaign.js cached for one day');
			foreach (['localStorage', 'sessionStorage', 'document.cookie', 'indexedDB', 'eval(', 'new Function', 'fetch(', 'sendBeacon', 'XMLHttpRequest'] as $needle) {
				Assert::assertTrue(!str_contains($js, $needle), 'campaign.js without ' . $needle);
			}
			[$status, $tag] = self::requestHttp('/?campaign-snippet=1', ['DNT: 1']);
			Assert::assertTrue($status === 200 && preg_match('#^\s*<script src="http://127\.0\.0\.1:' . $port . '/k/campaign\.js\?v=' . substr(md5_file($repo . '/views/campaign.js'), 0, 8) . '" data-kizami-campaign="cam" defer></script>\s*$#', $tag) === 1, 'Campaign snippet: one external script tag naming the parameter: ' . $tag);
			$campaignUrl = fn (string $url, string $query = '&cam=social-tg-test'): string => self::requestHttp('/?campaign-url=' . rawurlencode($url) . $query, ['DNT: 1'])[1];
			Assert::assertSame('https://www.digistore24.com/product/123456?ds24tr=social-tg-test', $campaignUrl('https://www.digistore24.com/product/123456?cam=old'), 'campaignUrl: an order link takes the request tag as ds24tr, the own cam goes');
			Assert::assertSame('https://www.digistore24.com/product/123456?aff=p&cam=k', $campaignUrl('https://www.digistore24.com/product/123456?aff=p&cam=k'), 'campaignUrl: an affiliate link stays');
			Assert::assertSame('/events?cam=social-tg-test', $campaignUrl('/events'), 'campaignUrl: an own link takes the parameter');
			Assert::assertSame('http://127.0.0.1/events?cam=social-tg-test', $campaignUrl('http://127.0.0.1/events'), 'campaignUrl: the index host is an own host');
			Assert::assertSame('https://other.example.org/x', $campaignUrl('https://other.example.org/x'), 'campaignUrl: another host stays');
			Assert::assertSame('/events?cam=autumn', $campaignUrl('/events', '&cam=social-tg-test&utm_campaign=autumn'), 'campaignUrl: utm_campaign wins');
			Assert::assertSame('/events', $campaignUrl('/events', ''), 'campaignUrl: no tag, no change');
			self::writeConfig(array_merge($config, ['kizami.campaignParam' => 'cam', 'kizami.active' => false]));
			Assert::assertTrue(self::requestHttp('/k/campaign.js')[0] === 404 && trim(self::requestHttp('/?campaign-snippet=1', ['DNT: 1'])[1]) === '', 'Switched off: no campaign script, the snippet prints nothing');
			self::writeConfig($config + ['kizami.campaignParam' => ['cam']]);
			Assert::assertTrue(self::requestHttp('/k/campaign.js')[0] === 404 && trim(self::requestHttp('/?campaign-snippet=1', ['DNT: 1'])[1]) === '', 'Unusable campaignParam: no campaign script, the snippet prints nothing');
			Assert::assertTrue(self::requestHttp('/?cam=x')[0] === 200 && $lastCampaign() === '', 'campaignParam: non-string option is not set');
			self::writeConfig($config + ['kizami.campaignParam' => '']);
			Assert::assertTrue(self::requestHttp('/?cam=x')[0] === 200 && $lastCampaign() === '', 'campaignParam: empty option is not set');
			self::writeConfig($config);
			Assert::assertTrue(self::requestHttp('/?cam=x')[0] === 200 && $lastCampaign() === '', 'Without campaignParam, cam is ignored');
			Assert::assertTrue(self::requestHttp('/k/campaign.js')[0] === 404 && trim(self::requestHttp('/?campaign-snippet=1', ['DNT: 1'])[1]) === '', 'Without campaignParam: /k/campaign.js is a real 404, the snippet prints nothing');
			Assert::assertSame('/events', $campaignUrl('/events'), 'Without campaignParam: campaignUrl() leaves an own link alone');
			Assert::assertSame('https://www.digistore24.com/product/123456', $campaignUrl('https://www.digistore24.com/product/123456'), 'Without campaignParam: the request has no tag, the order link stays');

			// Invalid UTF-8. An anonymous request can send any bytes in
			// Referer and in the redirect's ?from=. They must not reach kizami_events
			// as invalid UTF-8: json_encode() of the report failed on them, and
			// /k/report answered 200 with an empty body.
			$lastRow = function (string $column, string $where = "type='page'"): string {
				global $tmp;
				$d = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
				$value = (string)$d->querySingle("SELECT $column FROM kizami_events WHERE $where ORDER BY id DESC LIMIT 1");
				$d->close();
				return $value;
			};
			$beforeBad = self::rowCount();
			Assert::assertTrue(self::requestHttp('/', ['Referer: https://foo' . "\xFF" . '.example/'])[0] === 200, 'Referer with an invalid byte: the page still answers 200');
			Assert::assertTrue(self::rowCount() === $beforeBad + 1, 'Referer with an invalid byte: the visit is still counted');
			Assert::assertTrue($lastRow('referrer') === '', 'Referer with an invalid byte: no host stored');
			Assert::assertTrue(self::requestHttp('/', ['Referer: https://foo.example/' . "\xFF"])[0] === 200 && $lastRow('referrer') === 'foo.example', 'Invalid byte after the host: the host is kept');
			Assert::assertTrue(self::requestHttp('/', ['Referer: https://Search.Example.org/?q=1'])[0] === 200 && $lastRow('referrer') === 'search.example.org', 'Valid referrer unchanged');
			// Paths. The page path: Kirby keeps percent-encoding, so %FF arrives as
			// ASCII text and is stored as sent. A raw invalid byte never gets that far
			// (PHP's test server drops the connection; Kirby's router throws "No
			// route found" before route:after). The way in with real bytes is the
			// redirect's ?from=: PHP decodes %FF there, and cleanPath() stores it.
			foreach (['/%FF', '/a%FFb', '/caf%C3%A9'] as $address) {
				Assert::assertTrue(self::requestHttp($address)[0] === 404, 'Percent-encoded path answers 404: ' . $address);
				Assert::assertTrue($lastRow('path', "type='notfound'") === $address, 'Percent-encoded path stored as sent (ASCII): ' . $address . ' → ' . $lastRow('path', "type='notfound'"));
			}
			$beforeFrom = self::rowCount();
			foreach (['%FFx' => '', '%2Fa%FFb' => '', '%2Fa%E2%82' => '', '%2Fcaf%C3%A9' => "/caf\u{E9}", '%2Fok%3Fx%3D%FF' => '/ok'] as $from => $stored) {
				[$status, , $headers] = self::requestHttp('/call?from=' . $from, ['Sec-Fetch-User: ?1']);
				Assert::assertTrue($status === 302 && in_array('Location: tel:+498000000', $headers, true), 'Redirect with from=' . $from . ' still redirects');
				Assert::assertTrue($lastRow('path', "type='redirect'") === $stored, 'Redirect from=' . $from . ' stores ' . json_encode($stored, JSON_UNESCAPED_UNICODE) . ', got ' . bin2hex($lastRow('path', "type='redirect'")));
			}
			Assert::assertTrue(self::rowCount() === $beforeFrom + 5, 'Redirect with an invalid from= is still counted, just without a path');
			$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			// SQLite has no UTF-8 validity check, so look at the rows in PHP.
			$invalid = 0;
			$rows = $db->query('SELECT referrer, path FROM kizami_events');
			while ($row = $rows->fetchArray(SQLITE3_NUM)) {
				$invalid += preg_match('//u', (string)$row[0]) === 1 && preg_match('//u', (string)$row[1]) === 1 ? 0 : 1;
			}
			Assert::assertTrue($invalid === 0, 'No referrer or path with invalid UTF-8 in the database (' . $invalid . ' rows)');
			$db->close();

			// The report with a row from before the fix: the database may still hold
			// a host with an invalid byte. Needs the report switched on and an account
			// with the bundled role (the plugin registers it only with kizami.report).
			$config['kizami.report'] = true;
			self::writeConfig($config);
			require_once $repo . '/vendor/autoload.php';
			$setup = new Kirby\Cms\App([
				'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'site' => $tmp . '/site', 'content' => $tmp . '/content', 'storage' => $tmp . '/storage', 'accounts' => $tmp . '/site/accounts'],
				'options' => ['url' => 'http://127.0.0.1:' . $port] + $config,
			]);
			$setup->impersonate('kirby');
			$setup->users()->create(['email' => 'report@example.invalid', 'role' => 'kizami-report', 'password' => 'report-password-very-long-123']);
			Kirby\Cms\App::destroy();
			$reportAuth = ['Authorization: Basic ' . base64_encode('report@example.invalid:report-password-very-long-123')];
			$yesterday = (new DateTimeImmutable('yesterday 12:00', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			// CAST(… AS TEXT): a row written by Store is TEXT, a bare X'…' would be a BLOB.
			$db->exec("INSERT INTO kizami_events (created_at, type, action, path, target, referrer, utm_source, utm_medium, utm_campaign, session, device, position, seconds)
        VALUES ('$yesterday', 'page', 'view', '/', '', CAST(X'666F6FFF2E6578616D706C65' AS TEXT), '', '', '', 'legacy-1', 'desktop', '', 0)");
			$db->exec("INSERT INTO kizami_events (created_at, type, action, path, target, referrer, utm_source, utm_medium, utm_campaign, session, device, position, seconds)
        VALUES ('$yesterday', 'page', 'view', CAST(X'2F61FF62' AS TEXT), '', 'google.com', '', '', '', 'legacy-2', 'desktop', '', 0)");
			Assert::assertTrue($db->querySingle("SELECT typeof(referrer) || ':' || hex(referrer) FROM kizami_events WHERE session='legacy-1'") === 'text:666F6FFF2E6578616D706C65', 'Fixture row: the invalid host really is stored as TEXT');
			$db->close();
			[$status, $body, $headers] = self::requestHttp('/k/report?kind=day', $reportAuth);
			Assert::assertTrue($status === 200 && strlen($body) > 0, 'Report with a stored invalid host: a body, not an empty 200 (status ' . $status . ', ' . strlen($body) . ' bytes)');
			Assert::assertTrue(preg_grep('#^Content-Type: application/json#i', $headers) !== [], 'Report with a stored invalid host: JSON content type');
			$report = json_decode($body, true);
			Assert::assertTrue(json_last_error() === JSON_ERROR_NONE && is_array($report) && $report['ok'] === true && $report['format'] === 2, 'Report with a stored invalid host: valid JSON, ok, format 2');
			Assert::assertTrue(($report['sources']["foo\u{FFFD}.example"] ?? null) === 1, 'Report: the invalid host appears with U+FFFD: ' . json_encode(array_keys($report['sources'] ?? []), JSON_UNESCAPED_UNICODE));
			Assert::assertTrue(($report['pages']["/a\u{FFFD}b"] ?? null) === 1, 'Report: the invalid path appears with U+FFFD: ' . json_encode(array_keys($report['pages'] ?? []), JSON_UNESCAPED_UNICODE));
			Assert::assertTrue(str_contains($report['text'], "foo\u{FFFD}.example"), 'Report: the text shows the substituted host');
			// The dashboard shows the same row, not an empty cell.
			[$status, $html] = self::requestHttp('/k/dashboard?days=7', $auth);
			Assert::assertTrue($status === 200 && str_contains($html, "foo\u{FFFD}.example"), 'Dashboard shows the stored invalid host with U+FFFD');
			unset($config['kizami.report']); // reportWithoutHttps stays: the fixture speaks HTTP
			self::writeConfig($config);

			// Server handles sequential requests, no open request/DB writer here.
			foreach (glob($tmp . '/storage/kizami/kizami.sqlite*') as $file) {
				unlink($file);
			}
			file_put_contents($tmp . '/storage/kizami/kizami.sqlite', 'not a database');
			Assert::assertTrue(self::requestHttp('/?utm_source[]=test')[0] === 200, 'Broken DB keeps the website working');
			Assert::assertTrue(self::requestHttp('/call?from[]=test')[0] === 302, 'Broken DB keeps the redirect working');
			Assert::assertTrue(self::requestHttp('/k/image', [$origin], $post)[0] === 204, 'Broken DB does not break the image gallery');
			Assert::assertTrue(self::requestHttp('/k/event', [$origin], $download)[0] === 204, 'Broken DB: /k/event still answers 204');
			self::writeConfig($config, $routes);
			[$status, $html] = self::requestHttp('/count/shortcode_access');
			Assert::assertTrue($status === 200 && str_ends_with($html, 'Book'), 'Broken DB: the server-side action keeps the page working');
			self::writeConfig($config);
			[$status, $html] = self::requestHttp('/k/dashboard', $auth);
			Assert::assertTrue($status === 503 && str_contains($html, 'currently unavailable'), 'Broken DB shows a controlled warning');
			Assert::assertTrue(!str_contains($html, 'not a database') && !str_contains($html, 'class="tiles"'), 'No DB internals or false zero numbers');
			// Sites without content/error/ (for example a freshly created site): the
			// hidden route stays a 404 instead of a 500 from errorPage() === null.
			unlink($tmp . '/content/error/error.txt');
			rmdir($tmp . '/content/error');
			Assert::assertTrue(self::requestHttp('/k/report')[0] === 404, 'Without an error page the hidden route stays 404');
		} catch (Throwable $e) {
			throw $e;
		} finally {
			if (is_resource($process)) {
				proc_terminate($process);
				proc_close($process);
			}
			if (is_dir($tmp)) {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
				foreach ($files as $file) {
					if ($file->isDir() && !$file->isLink()) {
						rmdir($file->getPathname());
					} else {
						unlink($file->getPathname());
					}
				}
				rmdir($tmp);
			}
		}
		$this->assertFalse($failed ?? false, 'no failure');
	}

	private static function writeConfig(array $config, string $source = ''): void
	{
		global $tmp;
		$ziel = $tmp . '/site/config/config.php';
		file_put_contents($ziel . '.tmp', '<?php return ' . var_export($config, true) . ($source === '' ? '' : ' + [' . $source . ']') . ';');
		rename($ziel . '.tmp', $ziel); // atomar: der Testserver darf nie eine halbe Datei lesen
	}
}
