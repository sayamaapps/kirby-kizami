<?php

/**
 * Storage root check (src/Setup.php): a missing root or one inside the web
 * root is refused, and what a visitor and the operator see then. In-process
 * with real Kirby.
 *   php tests/SetupTest.php
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Setup;
use Sayamaapps\Kizami\SetupError;

#[CoversClass(\Sayamaapps\Kizami\Setup::class)]
#[CoversClass(\Sayamaapps\Kizami\SetupError::class)]
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

	private static function same($expected, $actual, string $what): void
	{
		global $n;
		if ($expected !== $actual) {
			Assert::fail($what . ': ' . var_export($actual, true));
		}
		$n++;
		Assert::assertTrue(true);
	}

	public function testSuite(): void
	{
		global $n;
		$base = dirname(__DIR__);
		require $base . '/vendor/autoload.php';
		require_once $base . '/src/Setup.php';
		require_once $base . '/src/Migration.php';
		$logFile = sys_get_temp_dir() . '/kizami-setup-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		register_shutdown_function(fn () => @unlink($logFile));
		$n = 0;
		$tmp = sys_get_temp_dir() . '/kizami-setup-' . bin2hex(random_bytes(6));
		$failed = false;
		try {
			foreach (['public/a&b', 'public2', 'storage'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			$tmp = realpath($tmp);
			$public = $tmp . '/public';

			// Unit: the rule itself.
			self::same('Kizami: Kirby root "storage" is not set. Define it in index.php outside the web root (see README, Installation).', self::refused(null, $public), 'No storage root');
			self::same(true, self::refused('', $public) !== null && self::refused('  ', $public) !== null, 'Empty storage root');
			self::same(null, self::refused($tmp . '/storage', $public), 'Sibling of the web root is fine');
			self::same(null, self::refused($tmp . '/storage/', $public . '/'), 'Trailing slashes do not matter');
			self::same(null, self::refused($tmp, $public), 'A parent of the web root is outside it');
			self::same(null, self::refused($tmp . '/public2', $public), 'A directory with the same name prefix is not inside');
			self::same(null, self::refused($tmp . '/not/yet/there', $public), 'Missing storage directory outside the web root');
			self::same(
				'Kizami: storage root ' . $public . '/storage lies inside the web root ' . $public . '. Move it outside; a web server would serve the database and the daily key as files.',
				self::refused($public . '/storage', $public),
				'Missing directory inside the web root'
			);
			self::same(true, self::refused($public, $public) !== null, 'Equal to the web root counts as inside');
			self::same(true, self::refused($public . '/a&b', $public) !== null, 'Existing directory inside the web root');
			self::same(true, self::refused($public . '/x/../a&b', $public) !== null, 'Dot segments of an existing path are resolved');
			self::same(true, self::refused($public . '/a/b/c', $public . '/') !== null, 'Deeply missing path inside, web root with a trailing slash');
			self::same(null, self::refused($tmp . '/storage', null), 'No index root: nothing to compare');

			// Symlinks: a document root and a storage root can both be links.
			symlink($public, $tmp . '/pub-link');
			symlink($tmp . '/storage', $tmp . '/storage-link');
			self::same(true, self::refused($tmp . '/pub-link/storage', $public) !== null, 'Storage inside a symlink to the web root');
			self::same(true, self::refused($public . '/data', $tmp . '/pub-link') !== null, 'Web root given as a symlink');
			self::same(null, self::refused($tmp . '/storage-link', $tmp . '/pub-link'), 'Both links, outside');
			symlink($public, $tmp . '/storage/in-public');
			self::same(true, self::refused($tmp . '/storage/in-public/x', $public) !== null, 'Link below the storage root that leads into the web root');

			// The data directory itself: storage/kizami (or the pre-1.0 kennzahlen) as a link into the web root.
			mkdir($tmp . '/storage2', 0700);
			symlink($public . '/a&b', $tmp . '/storage2/kizami');
			self::same(true, self::refused($tmp . '/storage2', $public) !== null, 'Data directory kizami linked into the web root');
			mkdir($tmp . '/storage3', 0700);
			symlink($public . '/a&b', $tmp . '/storage3/kennzahlen');
			self::same(true, self::refused($tmp . '/storage3', $public) !== null, 'Legacy data directory kennzahlen linked into the web root');
			mkdir($tmp . '/storage4', 0700);
			symlink($tmp . '/storage', $tmp . '/storage4/kizami');
			self::same(null, self::refused($tmp . '/storage4', $public), 'Data directory linked to a place outside the web root is fine');

			// Real Kirby.
			foreach (['site/plugins', 'site/config', 'site/templates', 'content/home', 'content/error'] as $dir) {
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
				$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('client' . ':' . 'client-password');
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
				self::same('Fixture', $body, "Page renders ($label)");
				self::same(true, str_contains((string)file_get_contents($logFile), 'Kizami: ' . $message), "Error log names the cause ($label)");
				self::same([], glob($tmp . '/public/a&b/storage/*') ?: [], "Nothing written below the web root ($label)");
				self::same([], glob($tmp . '/public/*.sqlite') ?: [], "No database in the web root ($label)");

				$response = $app($storage, 'https://example.invalid/call?from=%2F')->call('call', 'GET');
				self::same([302, 'tel:+4912345'], [$response->code(), $response->headers()['Location'] ?? null], "Redirect still redirects ($label)");

				// The file login needs the storage directory (lockout counters), so
				// without a usable root it is denied without checking the password:
				// 503 with a pointer to the error log, no challenge, no path. The
				// Panel login does not need it and shows the cause.
				$login();
				file_put_contents($logFile, '');
				$response = $app($storage, 'https://example.invalid/k/dashboard')->call('k/dashboard', 'GET');
				self::same(503, $response->code(), "File login without a usable root: 503 ($label)");
				self::same('Kizami: setup error, see the error log.', $response->body(), "...with a pointer and no path ($label)");
				self::same(false, isset($response->headers()['WWW-Authenticate']), "...and no challenge ($label)");
				self::same(true, str_contains((string)file_get_contents($logFile), 'Kizami: ' . $message), "...the cause is logged ($label)");
				// In preview mode the module hides behind Kirby's 404.
				$response = $app($storage, 'https://example.invalid/k/dashboard', [], ['kizami.previewAccess' => ['user' => 'client', 'hash' => password_hash('client-password', PASSWORD_DEFAULT)]])->call('k/dashboard', 'GET');
				self::same(404, $response->code(), "Preview mode with a setup error: real 404 ($label)");
				unset($_SERVER['HTTP_AUTHORIZATION']);

				$kirby = $app($storage, 'https://example.invalid/k/dashboard');
				$kirby->impersonate('kirby');
				$response = $kirby->call('k/dashboard', 'GET');
				self::same(503, $response->code(), "Dashboard for the Panel user answers 503 ($label)");
				$html = $response->body();
				self::same(true, str_contains($html, htmlspecialchars('Kizami: ' . $message, ENT_QUOTES)), "Dashboard shows the setup message, escaped ($label)");
				self::same(true, str_contains($html, 'href="/k/dashboard.css"'), "503 page keeps the stylesheet ($label)");
				self::same(false, str_contains($html, 'a&b'), "No raw ampersand in the 503 page ($label)");
			}

			// A good root counts.
			$kirby = $app($tmp . '/storage', 'https://example.invalid/');
			self::same('Fixture', (string)$kirby->render('/')->body(), 'Page renders with a good root');
			self::same(true, is_file($tmp . '/storage/kizami/kizami.sqlite'), 'A good root: the database is written');
			$login();
			$response = $app($tmp . '/storage', 'https://example.invalid/k/dashboard')->call('k/dashboard', 'GET');
			self::same([200, false], [$response->code(), str_contains($response->body(), 'class="warning"')], 'A good root: dashboard 200 without warning');
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
