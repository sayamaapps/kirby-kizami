<?php

/**
 * Dashboard access with real Kirby, in-process: HTTPS only (same option as
 * report and snapshot) and the failed-login limit with Kirby's own options.
 *   php tests/DashboardAccessTest.php
 */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Sayamaapps\Kizami\Access::class)]
#[CoversClass(\Sayamaapps\Kizami\Runtime::class)]
#[RunTestsInSeparateProcesses]
final class DashboardAccessTest extends TestCase
{
	public function testSuite(): void
	{
		// Failure paths below raise PHP warnings on purpose (read-only directories,
		// a file where a directory belongs). They are expected, not failures.
		set_error_handler(static fn (): bool => true, E_WARNING);
		$base = dirname(__DIR__);
		require $base . '/vendor/autoload.php';
		$logFile = sys_get_temp_dir() . '/kizami-access-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		register_shutdown_function(fn () => @unlink($logFile));
		$tmp = sys_get_temp_dir() . '/kizami-access-' . bin2hex(random_bytes(6));
		$failed = false;
		try {
			foreach (['public', 'site/plugins', 'site/config', 'site/templates', 'content/home', 'content/error', 'storage'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($base, $tmp . '/site/plugins/kizami');
			file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
			file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
			file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
			file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
			file_put_contents($tmp . '/site/config/kizami.php', '<?php return ' . var_export([
				'user' => 'client', 'hash' => password_hash('client-password', PASSWORD_DEFAULT),
			], true) . ';');

			// The route as Kirby calls it; the login comes from $_SERVER like under PHP-FPM.
			$get = function (string $path, string $url, string|null $password = 'client-password', array $options = []) use ($tmp) {
				unset($_SERVER['HTTP_AUTHORIZATION']);
				if ($password !== null) {
					$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('client' . ':' . $password);
				}
				$kirby = new Kirby\Cms\App([
					'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
						'site' => $tmp . '/site', 'storage' => $tmp . '/storage'],
					'request' => ['method' => 'GET', 'url' => $url],
					'options' => array_merge(['url' => $url, 'kizami.active' => true], $options),
				]);
				$response = $kirby->call($path, 'GET');
				return [$response->code(), $response->body(), $response->headers()];
			};

			foreach (['k/dashboard', 'k/dashboard.css'] as $route) {
				$https = "https://example.invalid/$route";
				$http = "http://example.invalid/$route";
				Assert::assertSame(200, $get($route, $https)[0], "$route over HTTPS with the right login");
				[$code, $body, $headers] = $get($route, $http);
				Assert::assertSame([403, 'HTTPS only.', false], [$code, $body, isset($headers['WWW-Authenticate'])], "$route over HTTP with the right login: refused, no challenge");
				Assert::assertSame('no-store', $headers['Cache-Control'] ?? null, "$route refusal is not cached");
				[$code, , $headers] = $get($route, $http, null);
				Assert::assertSame([403, false], [$code, isset($headers['WWW-Authenticate'])], "$route over HTTP without login: 403, nothing to prompt");
				Assert::assertSame(200, $get($route, $http, options: ['kizami.reportWithoutHttps' => true])[0], "$route over HTTP with kizami.reportWithoutHttps");
				Assert::assertSame(403, $get($route, $http, options: ['kizami.reportWithoutHttps' => 'yes'])[0], "$route: only a true switch opens HTTP");
				Assert::assertSame(401, $get($route, $https, null)[0], "$route over HTTPS without login: the usual challenge");
				Assert::assertSame(404, $get($route, $http, options: ['kizami.active' => false])[0], "$route with the module off: real 404 over HTTP too");
			}
			Assert::assertSame(false, is_file($tmp . '/storage/kizami/logins.json'), 'Refused over HTTP: nothing counted');

			// Where the dashboard answers Kirby's 404 anyway, plain HTTP does not turn it into a 403.
			$previewOptions = ['kizami.previewAccess' => ['user' => 'client', 'hash' => password_hash('client-password', PASSWORD_DEFAULT)]];
			foreach (['k/dashboard', 'k/dashboard.css'] as $route) {
				Assert::assertSame(404, $get($route, "http://example.invalid/$route", options: $previewOptions)[0], "$route in preview over HTTP: real 404");
			}
			rename($tmp . '/site/config/kizami.php', $tmp . '/site/config/kizami.off');
			Assert::assertSame(404, $get('k/dashboard', 'http://example.invalid/k/dashboard')[0], 'No access file over HTTP: real 404');
			rename($tmp . '/site/config/kizami.off', $tmp . '/site/config/kizami.php');
			Assert::assertSame(403, $get('k/dashboard', 'http://example.invalid/k/dashboard')[0], 'With the access file over HTTP: 403 (counter-check)');

			// Kirby's own options set the limit.
			$url = 'https://example.invalid/k/dashboard';
			$limit = ['auth.trials' => 2, 'auth.timeout' => 600];
			Assert::assertSame(401, $get('k/dashboard', $url, 'wrong', $limit)[0], 'auth.trials: failure 1');
			Assert::assertSame(401, $get('k/dashboard', $url, 'wrong', $limit)[0], 'auth.trials: failure 2');
			[$code, $body, $headers] = $get('k/dashboard', $url, 'client-password', $limit);
			Assert::assertSame([429, 'Too many failed logins. Try again later.', false], [$code, $body, isset($headers['WWW-Authenticate'])], 'auth.trials 2: blocked on the third attempt');
			$wait = (int)($headers['Retry-After'] ?? 0);
			Assert::assertSame(true, $wait > 590 && $wait <= 600, 'auth.timeout gives Retry-After');
			Assert::assertSame(200, $get('k/dashboard', $url, 'client-password', ['auth.trials' => 3] + $limit)[0], 'A higher limit lets the same address in again (counter-check)');
			unlink($tmp . '/storage/kizami/logins.json');

			// Kirby 404 stays a 404 where the module hides: preview without credentials.
			$preview = $limit + ['kizami.previewAccess' => ['user' => 'preview', 'hash' => password_hash('preview-password', PASSWORD_DEFAULT)]];
			Assert::assertSame(404, $get('k/dashboard', $url, 'wrong', $preview)[0], 'Preview: wrong login 1');
			Assert::assertSame(404, $get('k/dashboard', $url, 'wrong', $preview)[0], 'Preview: wrong login 2');
			Assert::assertSame(404, $get('k/dashboard', $url, 'preview-password', $preview)[0], 'Preview, blocked: still Kirby\'s 404, not a 429 that reveals the module');
			unlink($tmp . '/storage/kizami/logins.json');

			// Unusable lockout file: deny, log, never let in.
			file_put_contents($tmp . '/storage/kizami/logins.json', 'garbage');
			[$code, $body, $headers] = $get('k/dashboard', $url);
			Assert::assertSame([503, 'Kizami: login limit unavailable, see the error log.', false], [$code, $body, isset($headers['WWW-Authenticate'])], 'Garbage lockout file: 503 with a pointer, no challenge, no access');
			Assert::assertSame(true, str_contains((string)file_get_contents($logFile), 'Kizami: login lockout file not usable'), 'Garbage lockout file is logged');
			unlink($tmp . '/storage/kizami/logins.json');
			// A file where the storage directory should be: same.
			foreach (glob($tmp . '/storage/kizami/*') ?: [] as $f) {
				unlink($f);
			}
			rmdir($tmp . '/storage/kizami');
			file_put_contents($tmp . '/storage/kizami', 'x');
			file_put_contents($logFile, '');
			Assert::assertSame(401, $get('k/dashboard', $url, 'wrong')[0], 'Storage directory unusable: a failure is denied as usual');
			Assert::assertSame(true, str_contains((string)file_get_contents($logFile), 'Kizami: login lockout file not usable'), 'Uncountable failure is logged');
			unlink($tmp . '/storage/kizami');
			Assert::assertSame(200, $get('k/dashboard', $url)[0], 'Storage usable again: access (counter-check)');
		} catch (Throwable $e) {
			throw $e;
		} finally {
			if (is_dir($tmp)) {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
				foreach ($files as $f) {
					$f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
				}
				rmdir($tmp);
			}
		}
		restore_error_handler();
		$this->assertFalse($failed ?? false, 'no failure');
	}
}
