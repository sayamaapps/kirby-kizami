<?php

/**
 * Actions on routed and translated pages (1.4.0) in a running Kirby, without
 * HTTP: the path the snippet kizami/events puts on its script tag is the one
 * /k/event finds again — in every language, under a translated slug and for
 * a page a route serves under another address — and the server-side count
 * ($page->kizamiEvent()) with its rules, including that it never throws.
 *   php tests/PagePathTest.php
 */
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kizami\Capture;
use Kizami\Secret;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class PagePathTest extends TestCase
{
	private static function expect($expected, $actual, string $what): void
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
		$n = 0;
		$tmp = sys_get_temp_dir() . '/kizami-pagepath-' . bin2hex(random_bytes(6));
		$failed = false;
		$log = $tmp . '/php-errors.log';
		try {
			foreach (['public', 'site/plugins', 'site/templates', 'site/languages', 'content/home', 'content/error', 'content/books/kizami', 'storage'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($base, $tmp . '/site/plugins/kizami');
			file_put_contents($tmp . '/site/languages/de.php', '<?php return ["code" => "de", "default" => true, "name" => "Deutsch", "url" => "/"];');
			file_put_contents($tmp . '/site/languages/en.php', '<?php return ["code" => "en", "name" => "English", "url" => "/en"];');
			foreach (['de' => ['Start', 'Fehler', 'Bücher', 'Kizami'], 'en' => ['Home', 'Error', 'Books', 'Kizami']] as $language => [$home, $error, $books, $book]) {
				file_put_contents("$tmp/content/home/home.$language.txt", "Title: $home\n");
				file_put_contents("$tmp/content/error/error.$language.txt", "Title: $error\n");
				file_put_contents("$tmp/content/books/books.$language.txt", "Title: $books\n" . ($language === 'en' ? "----\nSlug: library\n" : ''));
				file_put_contents("$tmp/content/books/kizami/book.$language.txt", "Title: $book\n" . ($language === 'en' ? "----\nSlug: the-book\n" : ''));
			}
			file_put_contents($tmp . '/site/templates/default.php', '<?php snippet("kizami/events");');
			file_put_contents($tmp . '/site/templates/book.php', '<?php snippet("kizami/events");');
			$options = [
				'url' => 'https://example.invalid', 'languages' => true,
				'kizami.active' => true,
				'kizami.events' => [
					'shortcode_access' => ['label' => 'Short code', 'detail' => true],
					'video_play' => ['label' => 'Video'],
				],
				// A short code served by a route of the site's own.
				'routes' => [['pattern' => 'EEAQ77', 'action' => fn () => page('books/kizami')]],
			];
			$app = fn (array $more = [], array $request = []) => new App([
				'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
					'site' => $tmp . '/site', 'storage' => $tmp . '/storage'],
				'options' => array_merge($options, $more),
				'request' => $request,
			]);

			// The path of a page: '/' or '/' and its ID, the same in every language,
			// and kizami_find_page() — the lookup of /k/event — finds it again.
			$kirby = $app();
			foreach (['de', 'en'] as $current) {
				$kirby->setCurrentLanguage($current);
				$pages = ['/' => $kirby->site()->homePage(), '/books' => page('books'), '/books/kizami' => page('books/kizami')];
				foreach ($pages as $path => $page) {
					self::expect($path, kizami_page_path($page), "path of {$page->id()} ($current)");
					foreach (['de', 'en'] as $lookup) {
						$kirby->setCurrentLanguage($lookup);
						self::expect($page->id(), kizami_find_page($path)?->id(), "$path found again ($current → $lookup)");
					}
					$kirby->setCurrentLanguage($current);
				}
			}
			// What the browser shows instead is no page for /k/event — the reason
			// for the attribute (multi-language addresses, short codes).
			$kirby->setCurrentLanguage('de');
			foreach (['/en/library/the-book', '/en', '/EEAQ77', '/de/books'] as $address) {
				self::expect(null, kizami_find_page($address), "address $address is no page for /k/event");
			}
			self::expect(null, kizami_page_path($kirby->site()->errorPage()), 'no path for the error page');
			self::expect(null, kizami_page_path(null), 'no path without a page');
			self::expect(null, kizami_page_path('/books'), 'no path for a string');

			// The snippet in the rendered page: under its own URL in both languages
			// and under the short code of the route.
			$tag = fn (string $html) => preg_match('#<script src="https://example\.invalid/k/event\.js\?v=[0-9a-f]{8}"(?: data-kizami-path="([^"]*)")? defer></script>#', $html, $m) === 1 ? ($m[1] ?? '') : null;
			foreach (['/books/kizami', '/en/library/the-book', '/EEAQ77', '/en/library', '/'] as $address) {
				$html = (string)$app()->render($address)->body();
				$expected = match ($address) {
					'/en/library' => '/books', '/' => '/', default => '/books/kizami'
				};
				self::expect($expected, $tag($html), "snippet at $address names the rendered page");
			}
			self::expect('', $tag((string)$app()->render('/missing')->body() . (string)$app()->snippet('kizami/events', [], true)), 'snippet without a page: no attribute');

			// Server side: $page->kizamiEvent(). kizami_write() reads $_SERVER.
			ini_set('error_log', $log);
			$rows = function () use ($tmp): array {
				$db = new SQLite3($tmp . '/storage/kizami/kizami.sqlite');
				$result = $db->query('SELECT event, detail, path, session FROM kizami_custom_events ORDER BY id');
				$all = [];
				while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
					$all[] = $row;
				}
				$db->close();
				return $all;
			};
			$browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'REMOTE_ADDR' => '198.51.100.7'];
			$_SERVER = array_merge($_SERVER, $browser);
			$kirby = $app();
			$kirby->setCurrentLanguage('en');
			$book = page('books/kizami');
			self::expect(true, $book->kizamiEvent('shortcode_access', 'EEAQ77 https://example.org/a?b=c') === $book, 'kizamiEvent() returns the page itself');
			$all = $rows();
			$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
			$session = Capture::sessionHash($browser['REMOTE_ADDR'], $browser['HTTP_USER_AGENT'], Secret::get($tmp . '/storage/kizami', $now->format('Y-m-d')), $now);
			self::expect([['event' => 'shortcode_access', 'detail' => 'EEAQ77 example.org/a', 'path' => '/books/kizami', 'session' => $session]], $all, 'counted with the page ID, cleaned detail and the day identifier of a visit');
			$book->kizamiEvent('video_play', 'dropped');
			$all = $rows();
			self::expect(['video_play', '', '/books/kizami'], array_values(array_slice(end($all), 0, 3)), 'detail dropped without detail => true');
			$count = count($rows());

			// Not counted.
			foreach ([
				'unknown key' => fn () => $book->kizamiEvent('download'),
				'invalid key' => fn () => $book->kizamiEvent("shortcode_access\n"),
				'key no string' => fn () => $book->kizamiEvent(['shortcode_access']),
				'error page' => fn () => $kirby->site()->errorPage()->kizamiEvent('shortcode_access'),
				'no page' => fn () => kizami_event('shortcode_access', null),
			] as $what => $call) {
				$call();
				self::expect($count, count($rows()), "not counted: $what");
			}
			foreach (['DNT' => ['HTTP_DNT' => '1'], 'GPC' => ['HTTP_SEC_GPC' => '1'], 'bot' => ['HTTP_USER_AGENT' => 'Googlebot/2.1'],
				'empty user agent' => ['HTTP_USER_AGENT' => ''], 'HeadlessChrome' => ['HTTP_USER_AGENT' => 'Mozilla/5.0 HeadlessChrome/130.0']] as $what => $server) {
				$_SERVER = array_merge($_SERVER, $browser, $server);
				$book->kizamiEvent('shortcode_access');
				unset($_SERVER['HTTP_DNT'], $_SERVER['HTTP_SEC_GPC']);
				self::expect($count, count($rows()), "not counted: $what");
			}
			$_SERVER = array_merge($_SERVER, $browser);
			$app([], ['method' => 'HEAD']);
			page('books/kizami')->kizamiEvent('shortcode_access');
			self::expect($count, count($rows()), 'not counted: HEAD request');
			$app(['kizami.active' => false]);
			page('books/kizami')->kizamiEvent('shortcode_access');
			self::expect($count, count($rows()), 'not counted: Kizami off');
			$app(['kizami.events' => []]);
			page('books/kizami')->kizamiEvent('shortcode_access');
			self::expect($count, count($rows()), 'not counted: no actions configured');
			$app([], ['method' => 'POST']);
			page('books/kizami')->kizamiEvent('shortcode_access');
			self::expect($count + 1, count($rows()), 'counter-check: a POST request counts');

			// Never throws: a failing page and a broken database end in the log.
			$kirby = $app();
			$throwing = new class (['slug' => 'boom']) extends Page {
				public function isErrorPage(): bool
				{
					throw new RuntimeException('page exploded');
				}
			};
			@unlink($log);
			self::expect(true, $throwing->kizamiEvent('shortcode_access') === $throwing, 'a throwing page: no exception, the page is returned');
			self::expect(true, str_contains((string)@file_get_contents($log), 'Kizami: page exploded'), 'a throwing page: logged');
			foreach (glob($tmp . '/storage/kizami/kizami.sqlite*') as $file) {
				unlink($file);
			}
			file_put_contents($tmp . '/storage/kizami/kizami.sqlite', 'not a database');
			@unlink($log);
			self::expect(true, page('books/kizami')->kizamiEvent('shortcode_access') instanceof Page, 'broken database: no exception');
			self::expect(true, str_contains((string)@file_get_contents($log), 'Kizami: '), 'broken database: logged');

		} catch (Throwable $e) {
			throw $e;
		} finally {
			@unlink($tmp . '/site/plugins/kizami');
			exec('rm -rf ' . escapeshellarg($tmp));
		}
		$this->assertFalse((bool)$failed, 'no failure');
	}
}
