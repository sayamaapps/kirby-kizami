<?php

/**
 * Subdirectory install: contact links and the dashboard stylesheet start with
 * the path of Kirby's index URL, the stored paths stay the page paths.
 * In-process with real Kirby.
 *   php tests/SubdirectoryTest.php
 */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class SubdirectoryTest extends TestCase
{
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
		$logFile = sys_get_temp_dir() . '/kizami-subdir-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		register_shutdown_function(fn () => @unlink($logFile));
		$n = 0;
		$tmp = sys_get_temp_dir() . '/kizami-subdir-' . bin2hex(random_bytes(6));
		$failed = false;
		try {
			foreach (['public', 'site/plugins', 'site/templates', 'content/home', 'content/contact', 'content/error', 'storage'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($base, $tmp . '/site/plugins/kizami');
			foreach (['home' => 'Home', 'contact' => 'Contact', 'error' => 'Error'] as $slug => $title) {
				file_put_contents("$tmp/content/$slug/" . ($slug === 'contact' ? 'default' : $slug) . '.txt', "Title: $title\n");
			}
			file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
			file_put_contents($tmp . '/site/templates/home.php', '<?php echo "Fixture";');
			file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
			$_SERVER = array_merge($_SERVER, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0', 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_SEC_FETCH_USER' => '?1']);

			$app = fn (string $index, string $path = '', array $query = []) => new Kirby\Cms\App([
				'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
					'site' => $tmp . '/site', 'storage' => $tmp . '/storage'],
				'request' => ['method' => 'GET', 'url' => $index . $path, 'query' => $query],
				'options' => ['url' => $index, 'kizami.active' => true, 'kizami.redirects' => ['call' => 'tel:+4912345']],
			]);

			foreach (['' => ['', 'https://example.invalid'], '/subsite' => ['/subsite', 'https://example.invalid/subsite'], '/a/b/' => ['/a/b', 'https://example.invalid/a/b/']] as $label => [$prefix, $index]) {
				$kirby = $app($index);
				$home = $kirby->site()->homePage();
				$contact = $kirby->page('contact');
				self::same($prefix . '/call?from=%2F', $home->redirectLink('call'), "Home link ($index)");
				self::same($prefix . '/call?from=%2Fcontact', $contact->redirectLink('call'), "Subpage link ($index)");
				self::same('tel:+1', $home->redirectLink('missing', 'tel:+1'), "Fallback stays untouched ($index)");
				self::same(true, str_contains($contact->redirectAnchor('call', 'Call', 'tel:+1', '', ['class' => 'button']), 'href="' . $prefix . '/call?from=%2Fcontact"'), "Anchor carries the prefixed href ($index)");

				$kirby = $app($index, '/k/dashboard');
				$kirby->impersonate('kirby');
				$response = $kirby->call('k/dashboard', 'GET');
				self::same(200, $response->code(), "Dashboard ($index)");
				self::same(true, str_contains($response->body(), 'href="' . rtrim((string)parse_url($index, PHP_URL_PATH), '/') . '/k/dashboard.css"'), "Stylesheet link ($index)");
			}

			// The redirect below the subdirectory counts the page path, not the URL path.
			$kirby = $app('https://example.invalid/subsite');
			$kirby->render('/contact'); // the visitor's page view that a link click needs
			$response = $app('https://example.invalid/subsite', '/call?from=%2F', ['from' => '/'])->call('call', 'GET');
			self::same([302, 'tel:+4912345'], [$response->code(), $response->headers()['Location'] ?? null], 'Redirect under the subdirectory');
			$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
			$paths = [];
			$result = $db->query('SELECT type, path FROM kizami_events ORDER BY id');
			while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
				$paths[] = $row['type'] . ' ' . $row['path'];
			}
			self::same(['page /contact', 'redirect /'], $paths, 'Stored paths are page paths');

			// Broken storage: the 503 page still has the stylesheet.
			$kirby = new Kirby\Cms\App([
				'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site', 'storage' => $tmp . '/public/storage'],
				'request' => ['method' => 'GET', 'url' => 'https://example.invalid/subsite/k/dashboard'],
				'options' => ['url' => 'https://example.invalid/subsite', 'kizami.active' => true],
			]);
			$kirby->impersonate('kirby');
			$response = $kirby->call('k/dashboard', 'GET');
			self::same(503, $response->code(), 'Unavailable dashboard');
			self::same(true, str_contains($response->body(), 'href="/subsite/k/dashboard.css"'), 'Stylesheet link on the 503 page');
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
