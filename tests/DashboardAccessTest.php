<?php

/**
 * Dashboard access with real Kirby, in-process: Kirby accounts with the
 * dashboard role, a logged-in Panel user, HTTPS only, Kirby's own failed-login
 * limit (auth.trials), and a GET that writes nothing.
 *   php tests/DashboardAccessTest.php
 */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Sayamaapps\Kizami\Runtime::class)]
#[RunTestsInSeparateProcesses]
final class DashboardAccessTest extends TestCase
{
	private const ADMIN = 'admin@example.invalid:admin-password-very-long-123';
	private const DASHBOARD = 'dashboard@example.invalid:dashboard-password-very-long-123';

	public function testSuite(): void
	{
		$base = dirname(__DIR__);
		$tmp = sys_get_temp_dir() . '/kizami-access-' . bin2hex(random_bytes(6));
		// Kirby logs each failed login to the error log; keep it out of the test output.
		$logFile = $tmp . '-error.log';
		ini_set('error_log', $logFile);
		try {
			foreach (['public', 'site/plugins', 'site/config', 'site/templates', 'site/accounts', 'content/home', 'content/error', 'storage/kizami'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($base, $tmp . '/site/plugins/kizami');
			file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
			file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
			file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
			file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');

			$roots = [
				'index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
				'site' => $tmp . '/site', 'storage' => $tmp . '/storage', 'accounts' => $tmp . '/site/accounts',
			];
			$app = fn (string $url, array $options = []) => new Kirby\Cms\App([
				'roots' => $roots,
				'request' => ['method' => 'GET', 'url' => $url],
				'options' => array_merge(['url' => $url, 'kizami.active' => true], $options),
			]);

			// Accounts: the dashboard role exists while the module is active.
			$setup = $app('https://example.invalid/k/dashboard');
			$setup->impersonate('kirby');
			$setup->users()->create(['email' => 'admin@example.invalid', 'role' => 'admin', 'password' => 'admin-password-very-long-123']);
			$setup->users()->create(['email' => 'dashboard@example.invalid', 'role' => 'kizami-dashboard', 'password' => 'dashboard-password-very-long-123']);
			Assert::assertSame(true, isset($setup->extensions('blueprints')['users/kizami-dashboard']), 'Role kizami-dashboard exists while the module is active');

			// The route as Kirby calls it; the login comes from $_SERVER like under PHP-FPM.
			$get = function (string $route, string $url, string|null $login = self::DASHBOARD, array $options = []) use ($app) {
				unset($_SERVER['HTTP_AUTHORIZATION']);
				if ($login !== null) {
					$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($login);
				}
				$response = $app($url, $options)->call($route, 'GET');
				unset($_SERVER['HTTP_AUTHORIZATION']);

				return [$response->code(), $response->body(), $response->headers()];
			};

			foreach (['k/dashboard', 'k/dashboard.css'] as $route) {
				$https = "https://example.invalid/$route";
				$http = "http://example.invalid/$route";
				Assert::assertSame(200, $get($route, $https)[0], "$route over HTTPS with a dashboard account");
				[$code, $body, $headers] = $get($route, $http);
				Assert::assertSame([403, 'HTTPS only.', false], [$code, $body, isset($headers['WWW-Authenticate'])], "$route over HTTP: refused, no challenge");
				Assert::assertSame('no-store', $headers['Cache-Control'] ?? null, "$route refusal is not cached");
				Assert::assertSame(403, $get($route, $http, null)[0], "$route over HTTP without login: 403, nothing to prompt");
				Assert::assertSame(200, $get($route, $http, self::DASHBOARD, ['kizami.reportWithoutHttps' => true])[0], "$route over HTTP with kizami.reportWithoutHttps");
				Assert::assertSame(403, $get($route, $http, self::DASHBOARD, ['kizami.reportWithoutHttps' => 'yes'])[0], "$route: only a true switch opens HTTP");
				[$code, , $headers] = $get($route, $https, null);
				Assert::assertSame([401, true], [$code, isset($headers['WWW-Authenticate'])], "$route over HTTPS without login: the usual challenge");
				Assert::assertSame(401, $get($route, $https, 'dashboard@example.invalid:wrong')[0], "$route: wrong password");
				Assert::assertSame(401, $get($route, $https, 'nobody@example.invalid:dashboard-password-very-long-123')[0], "$route: unknown account");
				[$code, $body] = $get($route, $https, self::ADMIN);
				Assert::assertSame([403, 'Not allowed for this account.'], [$code, $body], "$route: an admin account without the dashboard role is refused");
				Assert::assertSame(200, $get($route, $https, self::ADMIN, ['kizami.dashboardRoles' => ['admin']])[0], "$route: kizami.dashboardRoles can name another role");
				Assert::assertSame(404, $get($route, $https, self::DASHBOARD, ['kizami.active' => false])[0], "$route with the module off: real 404");
			}

			// A logged-in Panel user is let in without Basic Auth, over HTTPS only.
			$panel = $app('https://example.invalid/k/dashboard');
			$panel->impersonate('kirby');
			Assert::assertSame(200, $panel->call('k/dashboard', 'GET')->code(), 'Logged-in Panel user: dashboard without Basic Auth');
			$panel = $app('http://example.invalid/k/dashboard');
			$panel->impersonate('kirby');
			Assert::assertSame(403, $panel->call('k/dashboard', 'GET')->code(), 'Logged-in Panel user over HTTP: still refused');

			// Kirby's own failed-login limit applies to the dashboard, per address and per account.
			$https = 'https://example.invalid/k/dashboard';
			$limit = ['auth.trials' => 2, 'auth.timeout' => 600];
			$logins = $tmp . '/site/accounts/.logins';
			@unlink($logins);
			Assert::assertSame(401, $get('k/dashboard', $https, 'dashboard@example.invalid:wrong', $limit)[0], 'auth.trials: failure 1');
			Assert::assertSame(401, $get('k/dashboard', $https, 'dashboard@example.invalid:wrong', $limit)[0], 'auth.trials: failure 2');
			[$code, , $headers] = $get('k/dashboard', $https, self::DASHBOARD, $limit);
			Assert::assertSame([401, true], [$code, isset($headers['WWW-Authenticate'])], 'auth.trials 2: the right password is refused after two failures');
			Assert::assertSame(200, $get('k/dashboard', $https, self::DASHBOARD, ['auth.trials' => 3] + $limit)[0], 'A higher limit lets the same address in again (counter-check)');
			Assert::assertSame(true, is_file($logins), 'Kirby keeps the failed logins in its own log (accounts/.logins)');
			@unlink($logins);

			// GET writes nothing: a stale daily key stays, and the database is unchanged.
			$keyFile = $tmp . '/storage/kizami/secret.txt';
			file_put_contents($keyFile, "2010-01-01\nSTALE-SECRET-VALUE");
			$db = $tmp . '/storage/kizami/kizami.sqlite';
			$state = fn () => [is_file($db) ? hash_file('sha256', $db) : null, is_file($db . '-wal') ? filesize($db . '-wal') : 0];
			Assert::assertSame(200, $get('k/dashboard', $https)[0], 'Dashboard with a login');
			$before = $state();
			Assert::assertSame("2010-01-01\nSTALE-SECRET-VALUE", file_get_contents($keyFile), 'Dashboard GET leaves a stale daily key alone');
			Assert::assertSame(200, $get('k/dashboard', $https)[0], 'Dashboard again');
			Assert::assertSame($before, $state(), 'Dashboard GET writes nothing to the database');
			// A failure is still written to the plugin's error log (1.x behaviour, not data).
			foreach (['kizami.sqlite-wal', 'kizami.sqlite-shm'] as $side) {
				@unlink($tmp . '/storage/kizami/' . $side);
			}
			file_put_contents($db, str_repeat('x', 4096));
			Assert::assertSame(503, $get('k/dashboard', $https)[0], 'Unreadable database: 503');
			Assert::assertSame(true, is_file($tmp . '/storage/kizami/errors.log'), 'Dashboard failure is written to errors.log');
		} finally {
			if (is_dir($tmp)) {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
				foreach ($files as $f) {
					$f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
				}
				rmdir($tmp);
			}
			@unlink($logFile);
		}
		Assert::assertTrue(true);
	}
}
