<?php

/**
 * Storage root check (src/Setup.php): a missing root or one inside the web
 * root is refused, and what a visitor and the operator see then. In-process
 * with real Kirby.
 *   php tests/SetupTest.php
 */
use Kizami\SetupError;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Setup;

#[CoversClass(\Sayamaapps\Kizami\Setup::class)]
#[CoversClass(\Kizami\SetupError::class)]
#[RunTestsInSeparateProcesses]
final class SetupTest extends TestCase
{
	private static function refused(string|null $storage, string|null $index): string|null
	{
		try {
			Setup::storageRoot($storage, $index);
			return null;
		} catch (SetupError $e) {
			return $e->getMessage();
		}
	}

	public function testSuite(): void
	{
		$base = dirname(__DIR__);
		require $base . '/vendor/autoload.php';
		require_once $base . '/src/Setup.php';
		require_once $base . '/src/Migration.php';
		$logFile = sys_get_temp_dir() . '/kizami-setup-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		register_shutdown_function(fn () => @unlink($logFile));
		$tmp = sys_get_temp_dir() . '/kizami-setup-' . bin2hex(random_bytes(6));
		$failed = false;
		try {
			foreach (['public/a&b', 'public2', 'storage'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			$tmp = realpath($tmp);
			$public = $tmp . '/public';

			// Unit: the rule itself.
			Assert::assertSame('Kizami: Kirby root "storage" is not set. Define it in index.php outside the web root (see README, Installation).', self::refused(null, $public), 'No storage root');
			Assert::assertSame(true, self::refused('', $public) !== null && self::refused('  ', $public) !== null, 'Empty storage root');
			Assert::assertSame(null, self::refused($tmp . '/storage', $public), 'Sibling of the web root is fine');
			Assert::assertSame(null, self::refused($tmp . '/storage/', $public . '/'), 'Trailing slashes do not matter');
			Assert::assertSame(null, self::refused($tmp, $public), 'A parent of the web root is outside it');
			Assert::assertSame(null, self::refused($tmp . '/public2', $public), 'A directory with the same name prefix is not inside');
			Assert::assertSame(null, self::refused($tmp . '/not/yet/there', $public), 'Missing storage directory outside the web root');
			Assert::assertSame('Kizami: storage root ' . $public . '/storage lies inside the web root ' . $public . '. Move it outside; a web server would serve the database and the daily key as files.', self::refused($public . '/storage', $public), 'Missing directory inside the web root');
			Assert::assertSame(true, self::refused($public, $public) !== null, 'Equal to the web root counts as inside');
			Assert::assertSame(true, self::refused($public . '/a&b', $public) !== null, 'Existing directory inside the web root');
			Assert::assertSame(true, self::refused($public . '/x/../a&b', $public) !== null, 'Dot segments of an existing path are resolved');
			Assert::assertSame(true, self::refused($public . '/a/b/c', $public . '/') !== null, 'Deeply missing path inside, web root with a trailing slash');
			Assert::assertSame(null, self::refused($tmp . '/storage', null), 'No index root: nothing to compare');

			// Symlinks: a document root and a storage root can both be links.
			symlink($public, $tmp . '/pub-link');
			symlink($tmp . '/storage', $tmp . '/storage-link');
			Assert::assertSame(true, self::refused($tmp . '/pub-link/storage', $public) !== null, 'Storage inside a symlink to the web root');
			Assert::assertSame(true, self::refused($public . '/data', $tmp . '/pub-link') !== null, 'Web root given as a symlink');
			Assert::assertSame(null, self::refused($tmp . '/storage-link', $tmp . '/pub-link'), 'Both links, outside');
			symlink($public, $tmp . '/storage/in-public');
			Assert::assertSame(true, self::refused($tmp . '/storage/in-public/x', $public) !== null, 'Link below the storage root that leads into the web root');

			// The data directory itself: storage/kizami (or the pre-1.0 kennzahlen) as a link into the web root.
			mkdir($tmp . '/storage2', 0700);
			symlink($public . '/a&b', $tmp . '/storage2/kizami');
			Assert::assertSame(true, self::refused($tmp . '/storage2', $public) !== null, 'Data directory kizami linked into the web root');
			mkdir($tmp . '/storage3', 0700);
			symlink($public . '/a&b', $tmp . '/storage3/kennzahlen');
			Assert::assertSame(true, self::refused($tmp . '/storage3', $public) !== null, 'Legacy data directory kennzahlen linked into the web root');
			mkdir($tmp . '/storage4', 0700);
			symlink($tmp . '/storage', $tmp . '/storage4/kizami');
			Assert::assertSame(null, self::refused($tmp . '/storage4', $public), 'Data directory linked to a place outside the web root is fine');

			// Real Kirby.
			foreach (['site/plugins', 'site/config', 'site/templates', 'content/home', 'content/error'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($base, $tmp . '/site/plugins/kizami');
			file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
			file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
			file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
			file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
			mkdir($tmp . '/site/accounts', 0700, true);
			// The dashboard login is a Kirby account with the role kizami-dashboard.
			$accounts = new Kirby\Cms\App([
				'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site'],
				'options' => ['url' => 'https://example.invalid', 'kizami.active' => true],
			]);
			$accounts->users()->create(['email' => 'dashboard@example.invalid', 'role' => 'kizami-dashboard', 'password' => 'dashboard-password-very-long-123']);

			$app = function (string|null $storage, string $url, array $request = [], array $extra = []) use ($tmp) {
				$roots = ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site'];
				if ($storage !== null) {
					$roots['storage'] = $storage;
				}
				return new Kirby\Cms\App([
					'roots' => $roots,
					'request' => ['method' => 'GET', 'url' => $url] + $request,
					'options' => [
						'url' => 'https://example.invalid', 'kizami.active' => true,
						'kizami.redirects' => ['call' => 'tel:+4912345'],
					] + $extra,
				]);
			};
			$visitor = fn () => $_SERVER = array_merge($_SERVER, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0', 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_SEC_FETCH_USER' => '?1']);
			$login = function () {
				$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('dashboard@example.invalid' . ':' . 'dashboard-password-very-long-123');
			};
			$visitor();

			$cases = [
				'inside the web root' => [$tmp . '/public/a&b/storage', 'storage root ' . $tmp . '/public/a&b/storage lies inside the web root'],
				'missing' => [null, 'Kirby root "storage" is not set'],
			];
			foreach ($cases as $label => [$storage, $message]) {
				file_put_contents($logFile, '');
				$kirby = $app($storage, 'https://example.invalid/');
				$body = (string)$kirby->render('/')->body();
				Assert::assertSame('Fixture', $body, "Page renders ($label)");
				Assert::assertSame(true, str_contains((string)file_get_contents($logFile), 'Kizami: ' . $message), "Error log names the cause ($label)");
				Assert::assertSame([], glob($tmp . '/public/a&b/storage/*') ?: [], "Nothing written below the web root ($label)");
				Assert::assertSame([], glob($tmp . '/public/*.sqlite') ?: [], "No database in the web root ($label)");

				$response = $app($storage, 'https://example.invalid/call?from=%2F')->call('call', 'GET');
				Assert::assertSame([302, 'tel:+4912345'], [$response->code(), $response->headers()['Location'] ?? null], "Redirect still redirects ($label)");

				// The Kirby login needs no storage: the account is checked first, then the
				// dashboard shows the setup problem (503). No challenge, the path is not shown.
				$login();
				file_put_contents($logFile, '');
				$response = $app($storage, 'https://example.invalid/k/dashboard')->call('k/dashboard', 'GET');
				Assert::assertSame(503, $response->code(), "Dashboard with a login and no usable root: 503 ($label)");
				Assert::assertSame(false, isset($response->headers()['WWW-Authenticate']), "...and no challenge ($label)");
				Assert::assertSame(true, str_contains((string)file_get_contents($logFile), 'Kizami: ' . $message), "...the cause is logged ($label)");
				// A wrong password is refused before the storage is looked at.
				$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('dashboard@example.invalid:wrong-password-123');
				$response = $app($storage, 'https://example.invalid/k/dashboard')->call('k/dashboard', 'GET');
				Assert::assertSame(401, $response->code(), "Wrong login with no usable root: login prompt ($label)");
				unset($_SERVER['HTTP_AUTHORIZATION']);

				$kirby = $app($storage, 'https://example.invalid/k/dashboard');
				$kirby->impersonate('kirby');
				$response = $kirby->call('k/dashboard', 'GET');
				Assert::assertSame(503, $response->code(), "Dashboard for the Panel user answers 503 ($label)");
				$html = $response->body();
				Assert::assertSame(true, str_contains($html, htmlspecialchars('Kizami: ' . $message, ENT_QUOTES)), "Dashboard shows the setup message, escaped ($label)");
				Assert::assertSame(true, str_contains($html, 'href="/k/dashboard.css"'), "503 page keeps the stylesheet ($label)");
				Assert::assertSame(false, str_contains($html, 'a&b'), "No raw ampersand in the 503 page ($label)");
			}

			// A good root counts.
			$kirby = $app($tmp . '/storage', 'https://example.invalid/');
			Assert::assertSame('Fixture', (string)$kirby->render('/')->body(), 'Page renders with a good root');
			Assert::assertSame(true, is_file($tmp . '/storage/kizami/kizami.sqlite'), 'A good root: the database is written');
			$login();
			$response = $app($tmp . '/storage', 'https://example.invalid/k/dashboard')->call('k/dashboard', 'GET');
			Assert::assertSame([200, false], [$response->code(), str_contains($response->body(), 'class="warning"')], 'A good root: dashboard 200 without warning');
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
		$this->assertFalse($failed ?? false, 'no failure');
	}
}
