<?php

/**
 * Checks the Kizami core without Kirby.
 *   php tests/CoreTest.php
 *
 * Everything here is Kirby-free — session hash, bot filter, cleaning, SQLite,
 * bcrypt check. The hook in index.php only passes values in; that is exactly
 * why the core can be checked without a running Kirby.
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Access;
use Sayamaapps\Kizami\Analysis;
use Sayamaapps\Kizami\Capture;
use Sayamaapps\Kizami\Comparison;
use Sayamaapps\Kizami\I18n;
/**
 * Redirect error_log into a throwaway file.
 *
 * Two checks trigger error paths on purpose (directory that cannot be
 * created, throwing closure). Their error_log lines would otherwise land on
 * stderr and look like real errors in a run that reports "all checks
 * passed" — the kind of noise people learn to ignore within a week. Here the
 * entry is CHECKED instead: that something is logged at all is part of the
 * contract (a silent failure would be worse than a loudly logged one).
 */
/** Renders views/dashboard.php for a data package, like the dashboard route. */
// === Capture: session hash ==============================================
// === Capture: bot filter ================================================
// === Capture: DNT / GPC =================================================
// === Capture: cleaning ==================================================
// Deliberate expectation: cleanPath() only removes <>"' — parentheses and
// slashes stay, a path without / would be no path. The XSS protection hangs
// on the angle brackets, and those are gone.
// Referrer host and path are stored as valid UTF-8 only: invalid
// bytes in a stored value used to make json_encode() fail, so /k/report
// answered 200 with an empty body. Valid hosts and paths stay byte for byte
// what they were.
// The cleaning must discard invalid input by itself: a deprecation here
// (a null from a failed preg_replace() passed on) would be a log line per request.
// A zero-width space in the host depends on the PHP build: an older
// parse_url() turns two of its three bytes into "_" (invalid UTF-8, the
// host is discarded), a newer one passes it through (the format character
// is removed). Both are fine; neither may raise a notice.
// === Secret =============================================================
// Key rotation limits linkability within the running installation.
// Counter-check for the rotation: the old secret is GONE, not just put
// aside. If it were still in the file, the whole reasoning would collapse.
// A failure may be quiet, but not invisible: without a log entry nobody
// would ever learn why the numbers are missing.
// rotate(): retire a stale key without a counted visit.
// Counter-check for the failure path: as root, permissions do not bite.
// expire(): remove a stale key, never make one (filtered visitors, the CLI job).
// Clean up (never more than our own throwaway file):
// === Store: schema & writing ============================================
// No 'target' in the array: missing keys must become '', otherwise every
// caller would have to know all the fields.
// Writing to a broken DB must THROW, not swallow — otherwise data would be
// lost silently on every page view. The hook catches it further up.
// take the schema away
// Counter-check for the error split: an unwritable storage/ MUST throw, so
// the caller can catch it. Carrying on silently would be data loss without a
// hint. (Found while testing: schema creation was only wrapped in try/catch, but
// SQLite3 reports rejected statements in the default error mode with
// `false` instead of an exception — the catch never fired. This check pins
// that down.)
// === Store: queries & compaction ========================================
// Three views of /services, one of /contact, two sessions, plus an ancient row.
// Five rows in the window: one with only a referrer, two with utm_source
// (one of them WITH a referrer next to it), two with neither.
// The first written row carries referrer AND utm_source. It counts as utm
// here, not as google.com: utm_source wins, because a campaign is the more
// precise answer than the domain it was clicked through. The same row must
// never count twice.
// === Access: file check (bcrypt) ========================================
// === Redirect: names and targets ========================================
// The target may come from a content field (the phone number is maintained
// in ONE place). If the closure throws, the route must not die with it.
// Counter-checks — every one MUST fire:
// The sneakiest one: //foreign.example looks like an own path, but is
// protocol-relative and lands on a foreign host.
// === Store: redirects ===================================================
// A page row next to them: it must NOT be counted.
/* A deploy check of the numbers belongs to the site, not to the package
   (for example a check script in the site repo), and is tested there. */
// === Dashboard: escaping ================================================
// path, referrer, utm and target come from the request URL. Without escaping
// this would be stored XSS going off in the client's Panel session — so this
// check is not cosmetics.
// Rows stored before 1.6.1 can hold invalid UTF-8 in referrer and path. The
// dashboard shows them with U+FFFD: htmlspecialchars() without
// ENT_SUBSTITUTE would return an empty string for the whole value.
// Setup message and stylesheet URL of the unavailable page.
// Readable names for redirects (kizami.targetNames), "Home" instead of "/".
// The German dashboard really is German: the language is set once per
// request, and the template takes every text from I18n.
// The CLI uses the same consistent snapshot path and reports scheduler failures.
// The job also retires a stale daily key; it removes it and never creates one.
// Restore rehearsal: a separate offline directory, never the production DB.
// === Clean up ===========================================================
// Only our own throwaway files, never more.
// === Device class =======================================================
// === Missing pages (404) ================================================
// Legacy pre-1.0 schema: German names, no device column,
// CHECK without notfound. The fixture has to stay German — it is what old
// installations have on disk.
// Second open: nothing changes, no errors, rows stay.
// An intermediate legacy state (device column present, notfound missing) is
// migrated as well; device values are kept.
// === Dwell time: migration, writing, median/buckets =====================
// Legacy state of 27.09.2026: 'bild'/position already there, 'dauer'/sekunden
// missing. The migration also maps device and the old example positions.
// Writing: only 'duration' carries seconds, every other type stays at 0 —
// and 'duration' counts as neither view, visit nor click.
// A second session reports ONLY dwell time, no page view.
// Median (even count) + bucket distribution + median per page.
// German bucket labels come from translations/de.php.
// Median (odd count).
// Empty data and the switch turned off.
// Comparison::text with its own minimum base: a median is no quantity, the
// rule has to check the real number of visits in the previous period.
// === Window, previous period, dashboard queries =========================
// Fixed reference time (8 pm, after every time of day used below): window()
// compares by ELAPSED time, so a test run at, say, 6 am would otherwise
// exclude the 10/12/15 o'clock events below as "not yet passed" and skew
// the counts.
// Current 7-day window: today 10:00 (any weekday), 3 days ago 15:00, 6 days ago 9:00
// Previous period (day -7 to -13): two views, one click
// Outside both windows
// The previous period no longer ends exactly where the current window
// starts (both windows compare the same elapsed time, so "until" is no
// longer the start of a day) — it ends n days before the current window's end.
// Seven Berlin calendar days, not seven UTC days: across a DST change the two differ by one hour.
// Fixed reference times on both sides of the DST changes (2026-10-25, 2027-03-28), independent of the day the suite runs.
// Session b clicks a second time: 2 clicks, but still only 1 visit with an action.
// === Window with a fixed reference time (same elapsed time) =============
// At 9 am with n=7: current window = from the start of day (today−6 00:00)
// until now (9 am today); previous period moved back by the same 7 days:
// from today−13 00:00 until today−7 09:00. Without this alignment the
// current window would be systematically shorter in the morning than the
// full previous period and show false minus percentages.
// Of the two previous-period events (today−7 10 am, today−7 8 am) only the
// one at 8 may count — the boundary is today−7 9 am, exclusive.
// === Names and comparison ===============================================
// German names: the same tokens, translated by translations/de.php.
// === Analysis ===========================================================
// tileNames overrides the target name just for the tile (plural, "Calls"
// instead of "Call"); without the key the target name stays.
// Readable month label in the long-term chart ("Sep 26" instead of "2026-09").
// Empty database: nothing may throw.
// The upper boundary is exclusive: count neither following periods nor missing pages.
// === Summary ============================================================

use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Redirect;
use Sayamaapps\Kizami\Secret;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Access::class)]
#[CoversClass(\Sayamaapps\Kizami\Analysis::class)]
#[CoversClass(\Sayamaapps\Kizami\Capture::class)]
#[CoversClass(\Sayamaapps\Kizami\Comparison::class)]
#[CoversClass(\Sayamaapps\Kizami\I18n::class)]
#[CoversClass(\Sayamaapps\Kizami\Names::class)]
#[CoversClass(\Sayamaapps\Kizami\Redirect::class)]
#[CoversClass(\Sayamaapps\Kizami\Secret::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class CoreTest extends TestCase
{
	private static function day(string $ymd): DateTimeImmutable
	{
		return new DateTimeImmutable($ymd, new DateTimeZone('Europe/Berlin'));
	}

	private static function maintenanceCli(array $args): int
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/../maintenance.php', ...$args],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		fclose($pipes[0]);
		stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		return proc_close($process);
	}

	private static function renderDashboard(array $data): string
	{
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		return (string)ob_get_clean();
	}

	public function testSuite(): void
	{
		// Failure paths below raise PHP warnings on purpose (read-only directories,
		// a file where a directory belongs). They are expected, not failures.
		set_error_handler(static fn (): bool => true, E_WARNING);
		$logFile = sys_get_temp_dir() . '/kizami-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		$h1 = Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', self::day('2026-08-25'));
		Assert::assertSame($h1, Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', self::day('2026-08-25')), 'Hash is deterministic for equal inputs');
		Assert::assertSame(false, $h1 === Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', self::day('2026-08-26')), 'Hash rotates across the day boundary');
		Assert::assertSame(false, $h1 === Capture::sessionHash('9.9.9.9', 'Firefox', 'secret', self::day('2026-08-25')), 'Hash differs for another IP');
		Assert::assertSame(false, $h1 === Capture::sessionHash('1.2.3.4', 'Chrome', 'secret', self::day('2026-08-25')), 'Hash differs for another user agent');
		Assert::assertSame('', Capture::sessionHash('1.2.3.4', 'Firefox', '', self::day('2026-08-25')), 'No secret, no hash — nothing may be recorded');
		Assert::assertSame(true, Capture::isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'), 'Known bot UA counts as a bot');
		Assert::assertSame(true, Capture::isBot(''), 'Empty UA counts as a bot — no real browser omits it');
		Assert::assertSame(false, Capture::isBot('Mozilla/5.0 (Macintosh) Firefox/130.0'), 'Normal browser is not a bot');
		Assert::assertSame(true, Capture::optedOut(['HTTP_DNT' => '1']), 'DNT: 1 suppresses capture');
		Assert::assertSame(true, Capture::optedOut(['HTTP_SEC_GPC' => '1']), 'Sec-GPC: 1 suppresses capture');
		Assert::assertSame(false, Capture::optedOut(['HTTP_USER_AGENT' => 'Firefox']), 'Without DNT/GPC the request counts');
		Assert::assertSame('/services', Capture::cleanPath('/services?utm_source=google'), 'Path loses the query string');
		Assert::assertSame('/scriptalert(1)/script', Capture::cleanPath('/<script>alert(1)</script>'), 'Path drops dangerous characters');
		Assert::assertSame(255, strlen(Capture::cleanPath('/' . str_repeat('a', 400))), 'Path is capped at 255 characters');
		Assert::assertSame('google.com', Capture::cleanReferrer('https://google.com/search?q=doctor'), 'Referrer is reduced to the domain');
		Assert::assertSame('', Capture::cleanReferrer(null), 'Referrer without a value gives an empty string');
		Assert::assertSame('scriptalert1script', Capture::cleanId('<script>alert(1)</script>'), 'utm value loses special characters');
		$printable = fn (string $s) => json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
		foreach ([
			['https://google.com/search?q=doctor', 'google.com'],
			['https://WWW.Example.COM./x', 'www.example.com'],
			['https://xn--mnchen-3ya.example/', 'xn--mnchen-3ya.example'],
			['http://192.0.2.1:8080/x', '192.0.2.1'],
			['http://[::1]/x', '[::1]'],
			['https://user:pw@example.org/', 'example.org'],
			['android-app://com.example.app/', 'com.example.app'],
			["https://m\u{FC}nchen.example/", "m\u{FC}nchen.example"],
			["https://example.org/\xFF", 'example.org'],            // only the host counts, not what follows it
			['https://' . str_repeat('a', 120) . '.example/', str_repeat('a', 100)],
			['garbage', ''],
			['https://', ''],
		] as [$url, $host]) {
			Assert::assertSame($host, Capture::cleanReferrer($url), 'Referrer host stays as it was: ' . $printable($url));
		}
		Assert::assertSame('(internal)', Capture::cleanReferrer('https://own.example/x', ['own.example']), 'Own host stays "(internal)"');
		$notices = [];
		set_error_handler(function (int $severity, string $message) use (&$notices) {
			$notices[] = $message;
			return true;
		});
		$invalidReferrers = [
			'invalid byte in the host'           => "https://foo\xFF.example/",
			'truncated sequence at the host end' => "https://foo.example\xC3/",
		];
		foreach ($invalidReferrers as $what => $url) {
			Assert::assertSame('', Capture::cleanReferrer($url), 'Referrer with ' . $what . ' is discarded');
		}
		$zeroWidth = Capture::cleanReferrer("https://a\u{200B}b.example/");
		Assert::assertSame(true, in_array($zeroWidth, ['', 'ab.example'], true), 'Referrer with a zero-width space in the host: discarded or cleaned, never stored as is');
		restore_error_handler();
		Assert::assertSame([], $notices, 'Discarding an invalid referrer raises no PHP notice or deprecation');
		Assert::assertSame('foo_bar.example', Capture::cleanReferrer("https://foo\x01bar.example/"), 'Referrer: control character is gone');
		Assert::assertSame('abcd.example', Capture::cleanReferrer("https://a<b>\"c'd.example/"), 'Referrer: <, >, " and \' are removed from the host');
		Assert::assertSame('ab.example', Capture::cleanReferrer("https://a\u{FEFF}b.example/"), 'Referrer: format character (BOM) is removed from the host');
		Assert::assertSame('', Capture::cleanReferrer('https://<>"\'/'), 'Referrer: nothing left after cleaning gives an empty string');
		$cutReferrer = Capture::cleanReferrer('https://a' . str_repeat("\u{E4}", 150) . '/');
		Assert::assertSame([100, 1], [preg_match_all('/./us', $cutReferrer), preg_match('//u', $cutReferrer)], 'Referrer is cut at 100 characters, not in the middle of one');
		Assert::assertSame('a' . str_repeat("\u{E4}", 99), $cutReferrer, 'Referrer cut keeps whole characters');
		foreach ([
			['/services?utm_source=google', '/services'],
			['/a%FFb', '/a%FFb'],                                   // Kirby keeps percent-encoding: pure ASCII
			["/caf\u{E9}", "/caf\u{E9}"],
			["/\u{20AC}", "/\u{20AC}"],
			["/ok?x=\xFF", '/ok'],                                  // the query is dropped before the check
			['/' . str_repeat('a', 255), '/' . str_repeat('a', 254)],
		] as [$path, $expected]) {
			Assert::assertSame($expected, Capture::cleanPath($path), 'Path stays as it was: ' . $printable($path));
		}
		Assert::assertSame('', Capture::cleanPath("/a\xFFb"), 'Path with an invalid byte is discarded');
		Assert::assertSame('', Capture::cleanPath("/a\xE2\x82"), 'Path with a truncated sequence is discarded');
		Assert::assertSame(false, str_contains(Capture::cleanPath("/a\xFFb"), "\u{FFFD}"), 'Path of an invalid byte: nothing is "repaired" into U+FFFD');
		$cutPath = Capture::cleanPath('/' . str_repeat("\u{20AC}", 100));
		Assert::assertSame([1 + 3 * 84, 1], [strlen($cutPath), preg_match('//u', $cutPath)], 'Path is cut at 255 bytes on a character boundary');
		Assert::assertSame('/' . str_repeat("\u{20AC}", 84), $cutPath, 'Path cut keeps whole characters');
		Assert::assertSame(255, strlen(Capture::cleanPath('/' . str_repeat('a', 400))), 'Path of 400 ASCII characters still ends at 255 bytes');
		Assert::assertSame(50, strlen(Capture::cleanId(str_repeat('a', 80))), 'utm value is capped at 50 characters');
		Assert::assertSame('', Capture::cleanId(null), 'utm value without content gives an empty string');
		$tmp = sys_get_temp_dir() . '/kizami-test-' . bin2hex(random_bytes(6));
		$s1 = Secret::get($tmp, '2026-09-20');
		Assert::assertSame(false, $s1 === '', 'Secret is not empty');
		Assert::assertSame(64, strlen($s1), 'Secret has full length (32 bytes hex)');
		Assert::assertSame($s1, Secret::get($tmp, '2026-09-20'), 'Second call on the same day returns the same secret');
		Assert::assertSame('0600', substr(sprintf('%o', fileperms($tmp . '/' . Secret::FILE)), -4), 'File has mode 0600');
		$s2 = Secret::get($tmp, '2026-09-21');
		Assert::assertSame(false, $s1 === $s2, 'A different secret on the next day');
		Assert::assertSame($s2, Secret::get($tmp, '2026-09-21'), 'The new secret is the valid one from now on');
		Assert::assertSame(false, str_contains((string)file_get_contents($tmp . '/' . Secret::FILE), $s1), 'The old secret is no longer in the file');
		$unwritable = '/proc/kizami-does-not-exist';
		Assert::assertSame('', Secret::get($unwritable, '2026-09-20'), 'Directory that cannot be created → empty string, no crash');
		Assert::assertSame(true, str_contains((string)@file_get_contents($logFile), 'secret directory cannot be created'), '… and writes a log entry');
		$rot = sys_get_temp_dir() . '/kizami-rotate-' . bin2hex(random_bytes(6));
		Assert::assertSame(true, Secret::rotate($rot, '2026-09-22'), 'rotate() with no key file: nothing to do, true');
		Assert::assertSame(false, file_exists($rot), '… and it creates neither directory nor key');
		mkdir($rot, 0755, true);
		Assert::assertSame([true, false], [Secret::rotate($rot, '2026-09-22'), is_file($rot . '/' . Secret::FILE)], 'rotate() in an existing directory without a key: true, no file');
		file_put_contents($rot . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		Assert::assertSame(true, Secret::rotate($rot, '2026-09-22'), 'rotate() replaces a stale key');
		[$rotDate, $rotValue] = explode("\n", (string)file_get_contents($rot . '/' . Secret::FILE), 2);
		Assert::assertSame('2026-09-22', $rotDate, '… the date line is the given day');
		Assert::assertSame([false, 64], [$rotValue === 'STALE-SECRET-VALUE', strlen($rotValue)], '… the value is new and full length');
		Assert::assertSame($rotValue, Secret::get($rot, '2026-09-22'), '… and it is the key get() returns now');
		Assert::assertSame('0600', substr(sprintf('%o', fileperms($rot . '/' . Secret::FILE)), -4), '… file mode stays 0600');
		$before = (string)file_get_contents($rot . '/' . Secret::FILE);
		Assert::assertSame(true, Secret::rotate($rot, '2026-09-22'), 'rotate() with a current key: true');
		Assert::assertSame($before, (string)file_get_contents($rot . '/' . Secret::FILE), '… the file is untouched byte for byte');
		Assert::assertSame([$rot . '/' . Secret::FILE], glob($rot . '/*'), 'rotate() leaves no temp files behind');
		file_put_contents($rot . '/' . Secret::FILE, 'garbage without date line');
		Assert::assertSame([true, '2026-09-22'], [Secret::rotate($rot, '2026-09-22'), explode("\n", (string)file_get_contents($rot . '/' . Secret::FILE))[0]], 'rotate() replaces a file without a date line');
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
			file_put_contents($rot . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
			chmod($rot, 0555);
			Assert::assertSame(false, Secret::rotate($rot, '2026-09-23'), 'rotate() reports false when a stale key cannot be replaced');
			Assert::assertSame("2010-01-01\nSTALE-SECRET-VALUE", (string)file_get_contents($rot . '/' . Secret::FILE), '… and the stale key stays (honest failure, not a silent success)');
			Assert::assertSame(true, str_contains((string)@file_get_contents($logFile), 'secret not writable'), '… with a log entry');
			chmod($rot, 0755);
		}
		@unlink($rot . '/' . Secret::FILE);
		@rmdir($rot);
		$exp = sys_get_temp_dir() . '/kizami-expire-' . bin2hex(random_bytes(6));
		Assert::assertSame(true, Secret::expire($exp, '2026-09-22'), 'expire() with no directory: true');
		Assert::assertSame(false, file_exists($exp), '… and it creates neither directory nor key');
		mkdir($exp, 0755, true);
		Assert::assertSame([true, false], [Secret::expire($exp, '2026-09-22'), is_file($exp . '/' . Secret::FILE)], 'expire() in an existing directory without a key: true, still no file');
		file_put_contents($exp . '/' . Secret::FILE, "2026-09-22\nCURRENT-SECRET-VALUE");
		Assert::assertSame(true, Secret::expire($exp, '2026-09-22'), 'expire() with a current key: true');
		Assert::assertSame("2026-09-22\nCURRENT-SECRET-VALUE", (string)file_get_contents($exp . '/' . Secret::FILE), '… the file is untouched byte for byte');
		file_put_contents($exp . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		Assert::assertSame([true, false], [Secret::expire($exp, '2026-09-22'), file_exists($exp . '/' . Secret::FILE)], 'expire() removes a stale key');
		Assert::assertSame([], glob($exp . '/*'), '… and leaves nothing else behind');
		file_put_contents($exp . '/' . Secret::FILE, 'garbage without date line');
		Assert::assertSame([true, false], [Secret::expire($exp, '2026-09-22'), file_exists($exp . '/' . Secret::FILE)], 'expire() removes a file without a date line');
		file_put_contents($exp . '/' . Secret::FILE, '');
		Assert::assertSame([true, false], [Secret::expire($exp, '2026-09-22'), file_exists($exp . '/' . Secret::FILE)], 'expire() removes an empty file');
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
			file_put_contents($exp . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
			chmod($exp, 0555);
			Assert::assertSame(false, Secret::expire($exp, '2026-09-23'), 'expire() reports false when a stale key cannot be removed');
			Assert::assertSame("2010-01-01\nSTALE-SECRET-VALUE", (string)file_get_contents($exp . '/' . Secret::FILE), '… and the stale key stays');
			chmod($exp, 0755);
		}
		@unlink($exp . '/' . Secret::FILE);
		@rmdir($exp);
		@unlink($tmp . '/' . Secret::FILE);
		@rmdir($tmp);
		$dbDir  = sys_get_temp_dir() . '/kizami-db-' . bin2hex(random_bytes(6));
		mkdir($dbDir, 0755, true);
		$dbPath = $dbDir . '/' . Store::FILE;
		$st = new Store($dbPath);
		Assert::assertSame([], $st->missingTables(), 'Schema is complete after creation');
		Assert::assertSame(null, $st->writeProbe(), 'Fresh DB is writable');
		$st->write([
			'type' => 'page', 'action' => 'view', 'path' => '/services',
			'referrer' => 'google.com', 'utm_source' => 'newsletter',
			'utm_medium' => '', 'utm_campaign' => '', 'session' => 'hashA',
		]);
		$db = new SQLite3($dbPath);
		$count = (int)$db->querySingle('SELECT COUNT(*) FROM kizami_events');
		Assert::assertSame(1, $count, 'One row was written');
		Assert::assertSame('', (string)$db->querySingle('SELECT target FROM kizami_events'), 'The missing field target is stored as an empty string');
		$db->close();
		$broken = new Store($dbDir . '/broken.sqlite');
		$db2 = new SQLite3($dbDir . '/broken.sqlite');
		$db2->exec('DROP TABLE kizami_events');
		$db2->close();
		$thrown = false;
		try {
			$broken->write([
				'type' => 'page', 'action' => 'view', 'path' => '/x',
				'referrer' => '', 'utm_source' => '', 'utm_medium' => '',
				'utm_campaign' => '', 'session' => 'hashB',
			]);
		} catch (\RuntimeException $e) {
			$thrown = true;
		}
		Assert::assertSame(true, $thrown, 'Writing to a broken schema throws');
		Assert::assertSame(['kizami_events'], $broken->missingTables(), 'missingTables detects the missing schema');
		$roFile = $dbDir . '/read-only.sqlite';
		touch($roFile);
		chmod($roFile, 0444);
		$thrownRo = false;
		try {
			new Store($roFile);
		} catch (\RuntimeException $e) {
			$thrownRo = true;
		}
		Assert::assertSame(true, $thrownRo, 'Schemaless read-only DB throws RuntimeException');
		chmod($roFile, 0644);
		$db3 = new SQLite3($dbPath);
		$db3->exec("INSERT INTO kizami_events (type,action,path,referrer,utm_source,session) VALUES
    ('page','view','/services','google.com','','hashA'),
    ('page','view','/services','','newsletter','hashA'),
    ('page','view','/services','','','hashB'),
    ('page','view','/contact','','','hashB')");
		$db3->exec("INSERT INTO kizami_events (type,action,path,created_at,session) VALUES
    ('page','view','/ancient','2019-01-01 12:00:00','hashC')");
		$db3->close();
		$pages = $st->viewsPerPage(30);
		Assert::assertSame('/services', $pages[0]['path'], 'Most viewed page is /services');
		Assert::assertSame(4, $pages[0]['count'], '/services has four views (including the row written above)');
		Assert::assertSame(2, $st->visits(30), 'Visits count distinct sessions (within 30 days: A and B)');
		$perDay = $st->viewsPerDay(30);
		Assert::assertSame(1, count($perDay), 'Views per day return exactly one day (everything is from today)');
		Assert::assertSame(5, $perDay[0]['count'], 'And count all five rows of today');
		$sources = array_column($st->sources(30), 'count', 'source');
		Assert::assertSame(1, $sources['google.com'] ?? 0, 'Sources know the referrer domain');
		Assert::assertSame(2, $sources['utm:newsletter'] ?? 0, 'With referrer AND utm, utm wins — the same row counts only once');
		Assert::assertSame(2, $sources[Names::DIRECT] ?? 0, 'Sources count the rest as direct');
		Assert::assertSame(5, array_sum($sources), 'The sum is the number of views in the window, nothing twice');
		$deleted = $st->compact();
		Assert::assertSame(1, $deleted, 'Compaction removes the row older than the retention (13 months)');
		$db4 = new SQLite3($dbPath);
		$rest = (int)$db4->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path='/ancient'");
		$db4->close();
		Assert::assertSame(0, $rest, 'The ancient row is gone');
		$hash  = password_hash('secret123', PASSWORD_DEFAULT);
		$creds = ['user' => 'client', 'hash' => $hash];
		Assert::assertSame(true, Access::checkFile($creds, 'client', 'secret123'), 'Right user + right password → granted');
		Assert::assertSame(false, Access::checkFile($creds, 'client', 'wrong'), 'Right user + wrong password → denied');
		Assert::assertSame(false, Access::checkFile($creds, 'stranger', 'secret123'), 'Wrong user → denied');
		Assert::assertSame(false, Access::checkFile(null, 'client', 'secret123'), 'Missing configuration → denied (fail closed, unlike a preview guard)');
		Assert::assertSame(false, Access::checkFile(['user' => 'client', 'hash' => ''], 'client', 'secret123'), 'Empty hash → denied');
		Assert::assertSame(false, Access::checkFile($creds, null, null), 'Missing input → denied');
		Assert::assertSame('call', Redirect::cleanName('Call'), 'Name is lower-cased and cleaned');
		Assert::assertSame('call', Redirect::cleanName('ca<>ll'), 'Special characters are dropped from the name');
		Assert::assertSame('plan-route_2', Redirect::cleanName('Plan-Route_2'), 'Hyphen and underscore stay');
		Assert::assertSame(50, strlen(Redirect::cleanName(str_repeat('a', 80))), 'Name is capped at 50 characters');
		Assert::assertSame('', Redirect::cleanName(null), 'Null gives an empty string');
		Assert::assertSame('tel:+4980317', Redirect::resolveTarget('tel:+4980317'), 'A string target is passed through');
		Assert::assertSame('tel:+4980317', Redirect::resolveTarget(fn () => 'tel:+4980317'), 'A closure target is called');
		Assert::assertSame('', Redirect::resolveTarget(function () {
			throw new \RuntimeException('broken');
		}), 'Throwing closure gives an empty string instead of a crash');
		Assert::assertSame(true, str_contains((string)@file_get_contents($logFile), 'redirect target cannot be resolved'), '… and logs why the target is missing');
		Assert::assertSame('', Redirect::resolveTarget(['tel:1']), 'Array target gives an empty string');
		Assert::assertSame(true, Redirect::targetAllowed('tel:+4980317'), 'tel: is allowed');
		Assert::assertSame(true, Redirect::targetAllowed('mailto:farm@example.org'), 'mailto: is allowed');
		Assert::assertSame(true, Redirect::targetAllowed('https://example.org/x'), 'https: is allowed');
		Assert::assertSame(true, Redirect::targetAllowed('/menu'), 'Own path is allowed');
		Assert::assertSame(false, Redirect::targetAllowed('javascript:alert(1)'), 'javascript: is forbidden');
		Assert::assertSame(false, Redirect::targetAllowed('data:text/html,<script>'), 'data: is forbidden');
		Assert::assertSame(false, Redirect::targetAllowed('http://example.org'), 'http: without TLS is forbidden');
		Assert::assertSame(false, Redirect::targetAllowed('//foreign.example/phishing'), 'Protocol-relative (//host) is forbidden');
		Assert::assertSame(false, Redirect::targetAllowed(''), 'Empty target is forbidden');
		$stR = new Store($dbDir . '/redirect.sqlite');
		foreach ([['/cafe', 'call'], ['/cafe', 'call'], ['/', 'call']] as [$pathR, $targetR]) {
			$stR->write([
				'type' => 'redirect', 'action' => 'click', 'path' => $pathR, 'target' => $targetR,
				'referrer' => '', 'utm_source' => '', 'utm_medium' => '',
				'utm_campaign' => '', 'session' => 'hashW',
			]);
		}
		$stR->write([
			'type' => 'page', 'action' => 'view', 'path' => '/cafe', 'target' => '',
			'referrer' => '', 'utm_source' => '', 'utm_medium' => '',
			'utm_campaign' => '', 'session' => 'hashW',
		]);
		$r = $stR->redirects(30);
		Assert::assertSame(2, count($r), 'Two origin pages grouped');
		Assert::assertSame('/cafe', $r[0]['path'], 'Most clicked origin comes first');
		Assert::assertSame(2, $r[0]['count'], 'With the right count');
		Assert::assertSame('call', $r[0]['target'], 'And the right target');
		$stX = new Store(':memory:');
		$stX->write(['type' => 'page', 'action' => 'view', 'path' => '/<script>alert(1)</script>', 'referrer' => 'utm:"><script>evil</script>']);
		for ($i = 0; $i < 7; $i++) {
			$stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'ca<script>ll</script>', 'path' => '/']);
		}
		$html = self::renderDashboard(Analysis::data($stX, new Names([], [], []), 30, []));
		Assert::assertSame(false, str_contains($html, '<script>alert(1)</script>'), 'Dashboard escapes the path — no raw <script>');
		Assert::assertSame(false, str_contains($html, '<script>evil</script>'), 'Dashboard escapes the source — no raw <script>');
		Assert::assertSame(false, str_contains($html, '<script>ll</script>'), 'Dashboard escapes the redirect target — no raw <script>');
		Assert::assertSame(true, str_contains($html, '&lt;script&gt;'), 'Escaped markup is present (proof that the value was rendered at all)');
		Assert::assertSame(false, str_contains(strtolower($html), 'onclick='), 'No inline event handler in the output (CSP break)');
		Assert::assertSame(true, str_contains($html, '>7<'), 'The redirect count is in the output');
		$stBad = new Store(':memory:');
		$stBad->write(['type' => 'page', 'action' => 'view', 'path' => "/a\xFFb", 'referrer' => "foo\xFF.example", 'session' => 'bad', 'device' => 'desktop']);
		$stBad->write(['type' => 'notfound', 'action' => 'view', 'path' => "/missing\xFFpage", 'session' => 'bad2']);
		$htmlBad = self::renderDashboard(Analysis::data($stBad, new Names([], [], []), 30, []));
		Assert::assertSame(true, str_contains($htmlBad, "foo\u{FFFD}.example"), 'Dashboard shows an invalid source with U+FFFD, not empty');
		Assert::assertSame(true, str_contains($htmlBad, "/a\u{FFFD}b"), 'Dashboard shows an invalid page path with U+FFFD, not empty');
		Assert::assertSame(true, str_contains($htmlBad, "<td>/missing\u{FFFD}page</td>"), 'Dashboard shows an invalid missing-page path with U+FFFD');
		Assert::assertSame(1, preg_match('//u', $htmlBad), 'Dashboard output stays valid UTF-8 with invalid stored bytes');
		$unavailable = self::renderDashboard(['days' => 30, 'error' => true, 'brand' => 'Test']);
		Assert::assertSame(1, substr_count($unavailable, 'class="warning"'), 'No setup message by default');
		Assert::assertSame(true, str_contains($unavailable, '<link rel="stylesheet" href="/k/dashboard.css">'), 'Stylesheet defaults to the domain root');
		$unavailable = self::renderDashboard(['days' => 30, 'error' => true, 'brand' => 'Test', 'setup' => 'Kizami: storage root /a&b/<x> is "bad"', 'cssUrl' => 'https://example.invalid/sub/k/dashboard.css']);
		Assert::assertSame(true, str_contains($unavailable, '<p class="warning">Kizami: storage root /a&amp;b/&lt;x&gt; is &quot;bad&quot;</p>'), 'Setup message is shown escaped under the warning');
		Assert::assertSame(false, str_contains($unavailable, '<x>'), 'Setup message is never printed raw');
		Assert::assertSame(true, str_contains($unavailable, '<link rel="stylesheet" href="https://example.invalid/sub/k/dashboard.css">'), 'Stylesheet URL from the data package');
		$unavailable = self::renderDashboard(['days' => 30, 'error' => true, 'brand' => 'Test', 'cssUrl' => '"><script>x</script>']);
		Assert::assertSame(false, str_contains($unavailable, '<script>x'), 'Stylesheet URL is escaped');
		$stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'call', 'path' => '/']);
		$stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'route', 'path' => '/contact']);
		$html = self::renderDashboard(Analysis::data($stX, new Names(['call' => 'Call'], [], []), 30, []));
		Assert::assertSame(true, str_contains($html, '<td>Call</td>'), 'Redirect with a readable name');
		Assert::assertSame(true, str_contains($html, '<td>route</td>'), 'Without a name the internal name stays');
		Assert::assertSame(true, str_contains($html, 'Home'), '"/" is called Home');
		I18n::setLanguage('de');
		$htmlDe = self::renderDashboard(Analysis::data($stX, new Names(['call' => 'Anruf'], [], []), 30, []));
		I18n::setLanguage('en');
		Assert::assertSame(true, str_contains($htmlDe, 'Startseite'), 'German dashboard: "/" is called Startseite');
		Assert::assertSame(true, str_contains($htmlDe, '<html lang="de">'), 'German dashboard: html lang is de');
		Assert::assertSame('', Capture::cleanId(['test']), 'Array utm is ignored');
		Assert::assertSame('', Capture::cleanPath([['x']]), 'Nested path is ignored');
		Assert::assertSame('(internal)', Capture::cleanReferrer('https://EXAMPLE.org./x', ['example.org']), 'Internal referrer stays separate from direct access');
		Assert::assertSame('other.org', Capture::cleanReferrer('https://other.org/x', ['example.org']), 'Foreign referrer stays external');
		Assert::assertSame(false, Access::checkFile(['user' => [], 'hash' => []], 'x', 'x'), 'Array in the access file is rejected');
		$dst = new Store($dbDir . '/dst.sqlite');
		$dstDb = new SQLite3($dbDir . '/dst.sqlite');
		foreach (['2025-03-29 23:30:00', '2025-03-30 22:30:00', '2025-10-25 22:30:00', '2025-10-26 23:30:00'] as $time) {
			$dstDb->exec("INSERT INTO kizami_events (type, action, created_at) VALUES ('page','view','$time')");
		}
		$daysDst = array_column($dst->viewsPerDay(3650), 'count', 'day');
		foreach (['2025-03-30', '2025-03-31', '2025-10-26', '2025-10-27'] as $date) {
			Assert::assertSame(1, $daysDst[$date] ?? 0, 'Berlin day grouping including daylight saving time: ' . $date);
		}
		$dstDb->exec("INSERT INTO kizami_events (type, created_at) VALUES ('page','2010-01-01 00:00:00')");
		$dst->write(['type' => 'page', 'action' => 'view']);
		Assert::assertSame(0, $dstDb->querySingle("SELECT count(*) FROM kizami_events WHERE created_at < '2011'"), 'A new view compacts without the dashboard');
		$dst->snapshot($dbDir . '/snapshot.sqlite');
		Assert::assertSame([1, false, false], [ord(file_get_contents($dbDir . '/snapshot.sqlite', false, null, 18, 1)), is_file($dbDir . '/snapshot.sqlite-wal'), is_file($dbDir . '/snapshot.sqlite-shm')], 'SQLite snapshot without WAL mode and side files');
		$backup = new SQLite3($dbDir . '/snapshot.sqlite');
		Assert::assertSame('ok', $backup->querySingle('PRAGMA integrity_check'), 'SQLite snapshot is consistent');
		Assert::assertSame($dstDb->querySingle('SELECT count(*) FROM kizami_events'), $backup->querySingle('SELECT count(*) FROM kizami_events'), 'SQLite snapshot contains the WAL data');
		$backup->close();
		$dstDb->close();
		$throws = false;
		try {
			$dst->snapshot($dbDir . '/snapshot.sqlite');
		} catch (RuntimeException) {
			$throws = true;
		}
		Assert::assertSame(true, $throws, 'Snapshot does not overwrite an existing file');
		Assert::assertSame(0, self::maintenanceCli(['compact', $dbDir]), 'Maintenance CLI compacts successfully');
		Assert::assertSame([0, false], [self::maintenanceCli(['compact', $dbDir]), is_file($dbDir . '/' . Secret::FILE)], 'Maintenance CLI without a key file creates none');
		file_put_contents($dbDir . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		Assert::assertSame([0, false], [self::maintenanceCli(['compact', $dbDir]), is_file($dbDir . '/' . Secret::FILE)], 'Maintenance CLI with a stale key succeeds and removes it');
		$cliBefore = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d') . "\nCURRENT-SECRET-VALUE";
		file_put_contents($dbDir . '/' . Secret::FILE, $cliBefore);
		self::maintenanceCli(['compact', $dbDir]);
		Assert::assertSame($cliBefore, (string)file_get_contents($dbDir . '/' . Secret::FILE), 'Maintenance CLI leaves a current key alone');
		@unlink($dbDir . '/' . Secret::FILE);
		Assert::assertSame(0, self::maintenanceCli(['snapshot', $dbDir, $dbDir . '/cli.sqlite']), 'Maintenance CLI snapshots successfully');
		$restoreDir = $dbDir . '/restore';
		mkdir($restoreDir, 0700);
		copy($dbDir . '/cli.sqlite', $restoreDir . '/' . Store::FILE);
		$restoredDb = new SQLite3($restoreDir . '/' . Store::FILE);
		Assert::assertSame('ok', $restoredDb->querySingle('PRAGMA integrity_check'), 'Offline restore passes integrity_check');
		Assert::assertSame(4, $restoredDb->querySingle("SELECT count(*) FROM kizami_events WHERE path='/services'"), 'Offline restore contains representative page rows');
		$restoredDb->close();
		$restored = new Store($restoreDir . '/' . Store::FILE);
		Assert::assertSame($st->viewsPerPage(30), $restored->viewsPerPage(30), 'Offline restore shows the same page counts');
		$restored->write(['type' => 'redirect', 'action' => 'click', 'path' => '/restore-test', 'target' => 'call']);
		Assert::assertSame('/restore-test', $restored->redirects(30)[0]['path'], 'Offline restore can write new clicks');
		unset($restored);
		array_map('unlink', glob($restoreDir . '/*') ?: []);
		rmdir($restoreDir);
		Assert::assertSame(1, self::maintenanceCli(['compact', $dbDir . '/not-there']), 'Maintenance CLI refuses a missing database');
		Assert::assertSame(1, self::maintenanceCli(['snapshot', $dbDir, $dbDir . '/cli.sqlite']), 'Maintenance CLI refuses an existing snapshot');
		array_map('unlink', glob($dbDir . '/*') ?: []);
		@rmdir($dbDir);
		@unlink($logFile);
		$ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
		$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
		$androidPhone = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
		$androidTablet = 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
		$desktop = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';
		Assert::assertSame('tablet', Capture::deviceClass($ipad), 'iPad is a tablet');
		Assert::assertSame('mobile', Capture::deviceClass($iphone), 'iPhone is mobile');
		Assert::assertSame('mobile', Capture::deviceClass($androidPhone), 'Android with Mobile is mobile');
		Assert::assertSame('tablet', Capture::deviceClass($androidTablet), 'Android without Mobile is a tablet');
		Assert::assertSame('desktop', Capture::deviceClass($desktop), 'Mac is desktop');
		Assert::assertSame('desktop', Capture::deviceClass(''), 'Empty is desktop');
		$dirG = sys_get_temp_dir() . '/kizami-device-' . bin2hex(random_bytes(4));
		mkdir($dirG, 0700);
		$stG = new Store($dirG . '/' . Store::FILE);
		$stG->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'h1', 'device' => 'mobile']);
		$dbG = new SQLite3($dirG . '/' . Store::FILE);
		Assert::assertSame('mobile', $dbG->querySingle('SELECT device FROM kizami_events'), 'Device class is stored');
		Assert::assertSame(true, in_array('device', Store::columns(), true), 'Column device is in the schema');
		$dbG->close();
		array_map('unlink', glob($dirG . '/*'));
		rmdir($dirG);
		$dirF = sys_get_temp_dir() . '/kizami-notfound-' . bin2hex(random_bytes(4));
		mkdir($dirF, 0700);
		$old = new SQLite3($dirF . '/' . Store::FILE);
		$old->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT)");
		$old->exec('CREATE INDEX idx_ereignisse_pfad ON ereignisse(pfad)');
		$old->exec('CREATE TABLE kennzahlen_tage (tag TEXT PRIMARY KEY NOT NULL, aufrufe INTEGER NOT NULL, klicks INTEGER NOT NULL, geraete INTEGER NOT NULL)');
		$old->exec("INSERT INTO ereignisse (typ,aktion,pfad,sitzung) VALUES ('seite','aufruf','/old','hashOld')");
		$old->close();
		$stF = new Store($dirF . '/' . Store::FILE);
		$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/does-not-exist', 'session' => 'h1']);
		$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/does-not-exist', 'session' => 'h2']);
		$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/also-missing', 'session' => 'h1']);
		$dbF = new SQLite3($dirF . '/' . Store::FILE);
		Assert::assertSame(1, $dbF->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path='/old'"), 'Migration keeps old rows');
		Assert::assertSame('', $dbF->querySingle("SELECT device FROM kizami_events WHERE path='/old'"), 'Migration adds device');
		Assert::assertSame(1, $dbF->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='idx_kizami_events_path'"), 'Path index exists after the migration');
		$dbF->close();
		$stF2 = new Store($dirF . '/' . Store::FILE);
		$dbF = new SQLite3($dirF . '/' . Store::FILE);
		Assert::assertSame(4, $dbF->querySingle('SELECT COUNT(*) FROM kizami_events'), 'Opening again does not migrate again (4 rows)');
		$dbF->close();
		$dirZ = sys_get_temp_dir() . '/kizami-intermediate-' . bin2hex(random_bytes(4));
		mkdir($dirZ, 0700);
		$mid = new SQLite3($dirZ . '/' . Store::FILE);
		$mid->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT, geraet TEXT NOT NULL DEFAULT '')");
		$mid->exec('CREATE TABLE kennzahlen_tage (tag TEXT PRIMARY KEY NOT NULL, aufrufe INTEGER NOT NULL, klicks INTEGER NOT NULL, geraete INTEGER NOT NULL)');
		$mid->exec("INSERT INTO ereignisse (typ,aktion,pfad,sitzung,geraet) VALUES ('seite','aufruf','/','h','tablet')");
		$mid->close();
		$stZ = new Store($dirZ . '/' . Store::FILE);
		$stZ->write(['type' => 'notfound', 'action' => 'view', 'path' => '/x', 'session' => 'h']);
		$mid = new SQLite3($dirZ . '/' . Store::FILE);
		Assert::assertSame('tablet', $mid->querySingle("SELECT device FROM kizami_events WHERE path='/'"), 'Intermediate state: device value is kept');
		$mid->close();
		array_map('unlink', glob($dirZ . '/*'));
		rmdir($dirZ);
		$notFound = $stF->notFound(30);
		Assert::assertSame(['path' => '/does-not-exist', 'count' => 2], $notFound[0], 'Missing pages: most frequent first');
		Assert::assertSame(1, count($stF->viewsPerPage(30)), 'Missing pages do not count as page views');
		Assert::assertSame(1, $stF->visits(30), 'Missing pages do not count as visits');
		array_map('unlink', glob($dirF . '/*'));
		rmdir($dirF);
		$dirD1 = sys_get_temp_dir() . '/kizami-dwell-mig-' . bin2hex(random_bytes(4));
		mkdir($dirD1, 0700);
		$oldD = new SQLite3($dirD1 . '/' . Store::FILE);
		$oldD->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung','fehlseite','bild')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT,
  geraet TEXT NOT NULL DEFAULT '', position TEXT NOT NULL DEFAULT '')");
		$oldD->exec("INSERT INTO ereignisse (id,typ,aktion,pfad,sitzung,geraet,position) VALUES (7,'seite','aufruf','/old','hashOld','mobil','kopf')");
		$oldD->close();
		$stD1 = new Store($dirD1 . '/' . Store::FILE);
		$dbD1 = new SQLite3($dirD1 . '/' . Store::FILE);
		Assert::assertSame(['id' => 7, 'path' => '/old', 'device' => 'mobile', 'position' => 'header', 'seconds' => 0], $dbD1->query('SELECT id,path,device,position,seconds FROM kizami_events')->fetchArray(SQLITE3_ASSOC), 'Migration to duration/seconds keeps the existing row');
		$stD1b = new Store($dirD1 . '/' . Store::FILE);
		Assert::assertSame(1, $dbD1->querySingle('SELECT COUNT(*) FROM kizami_events'), 'Opening again does not migrate again');
		$thrownDuration = false;
		try {
			$stD1->write(['type' => 'duration', 'action' => 'visible', 'path' => '/x', 'session' => 's', 'seconds' => 12]);
		} catch (\Throwable) {
			$thrownDuration = true;
		}
		Assert::assertSame(false, $thrownDuration, 'type=duration is allowed by the CHECK after the migration');
		Assert::assertSame(12, (int)$dbD1->querySingle("SELECT seconds FROM kizami_events WHERE type='duration'"), 'The written duration row carries its seconds');
		$dbD1->close();
		array_map('unlink', glob($dirD1 . '/*'));
		rmdir($dirD1);
		$dirD2 = sys_get_temp_dir() . '/kizami-dwell-write-' . bin2hex(random_bytes(4));
		mkdir($dirD2, 0700);
		$stD2 = new Store($dirD2 . '/' . Store::FILE);
		$stD2->write(['type' => 'duration', 'action' => 'visible', 'path' => '/contact', 'session' => 's1', 'seconds' => 37]);
		$stD2->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 's1']);
		$stD2->write(['type' => 'duration', 'action' => 'visible', 'path' => '/', 'session' => 'duration-only', 'seconds' => 9]);
		$dbD2 = new SQLite3($dirD2 . '/' . Store::FILE);
		Assert::assertSame(37, (int)$dbD2->querySingle("SELECT seconds FROM kizami_events WHERE type='duration' AND path='/contact'"), 'Duration row has its seconds');
		Assert::assertSame(0, (int)$dbD2->querySingle("SELECT seconds FROM kizami_events WHERE type='page'"), 'Page row stays at seconds=0 (default)');
		$dbD2->close();
		Assert::assertSame(1, $stD2->pageViews(30), 'Duration does not count as a page view');
		Assert::assertSame(1, $stD2->visits(30), 'A duration-only session does not count as a visit');
		Assert::assertSame(0, $stD2->clicks(30), 'Duration does not count as a click');
		array_map('unlink', glob($dirD2 . '/*'));
		rmdir($dirD2);
		$dirD3 = sys_get_temp_dir() . '/kizami-dwell-median-' . bin2hex(random_bytes(4));
		mkdir($dirD3, 0700);
		$stD3 = new Store($dirD3 . '/' . Store::FILE);
		foreach ([['a', 5], ['b', 15], ['c', 45], ['d', 200]] as [$session, $sec]) {
			$stD3->write(['type' => 'duration', 'action' => 'visible', 'path' => '/menu', 'session' => $session, 'seconds' => $sec]);
		}
		$anD3 = Analysis::data($stD3, new Names([], [], []), 30, ['dwellTime' => true]);
		Assert::assertSame(30, $anD3['dwellTime']['median'], 'Median of 4 values (5,15,45,200): mean of the two middle ones (15+45)/2');
		$buckets = array_column($anD3['dwellTime']['buckets'], 'value', 'name');
		Assert::assertSame(1, $buckets['under 10 s'], 'Bucket "under 10 s" contains 5 s');
		Assert::assertSame(1, $buckets['10–30 s'], 'Bucket "10–30 s" contains 15 s');
		Assert::assertSame(1, $buckets['30 s–1 min'], 'Bucket "30 s–1 min" contains 45 s');
		Assert::assertSame(0, $buckets['1–3 min'], 'Bucket "1–3 min" is empty');
		Assert::assertSame(1, $buckets['over 3 min'], 'Bucket "over 3 min" contains 200 s');
		$pagesD3 = array_column($anD3['dwellTime']['pages'], null, 'name');
		Assert::assertSame(4, $pagesD3['/menu']['measurements'], 'Per page: /menu has 4 measurements');
		Assert::assertSame(30, $pagesD3['/menu']['median'], 'Per page: the /menu median equals the overall median (only page)');
		Assert::assertSame(true, $anD3['dwellTime']['firstMeasurement'] !== null, 'First measurement is set');
		I18n::setLanguage('de');
		$bucketsDe = array_column(Analysis::data($stD3, new Names([], [], []), 30, ['dwellTime' => true])['dwellTime']['buckets'], 'value', 'name');
		I18n::setLanguage('en');
		Assert::assertSame(1, $bucketsDe['über 3 Min'] ?? null, 'German bucket "über 3 Min" contains 200 s');
		array_map('unlink', glob($dirD3 . '/*'));
		rmdir($dirD3);
		$dirD4 = sys_get_temp_dir() . '/kizami-dwell-median-odd-' . bin2hex(random_bytes(4));
		mkdir($dirD4, 0700);
		$stD4 = new Store($dirD4 . '/' . Store::FILE);
		foreach ([['a', 10], ['b', 20], ['c', 30]] as [$session, $sec]) {
			$stD4->write(['type' => 'duration', 'action' => 'visible', 'path' => '/', 'session' => $session, 'seconds' => $sec]);
		}
		$anD4 = Analysis::data($stD4, new Names([], [], []), 30, ['dwellTime' => true]);
		Assert::assertSame(20, $anD4['dwellTime']['median'], 'Median of 3 values (10,20,30) is the middle value');
		array_map('unlink', glob($dirD4 . '/*'));
		rmdir($dirD4);
		$dirD5 = sys_get_temp_dir() . '/kizami-dwell-empty-' . bin2hex(random_bytes(4));
		mkdir($dirD5, 0700);
		$stD5 = new Store($dirD5 . '/' . Store::FILE);
		$anD5 = Analysis::data($stD5, new Names([], [], []), 30, ['dwellTime' => true]);
		Assert::assertSame(null, $anD5['dwellTime']['firstMeasurement'], 'Without measurements: firstMeasurement is null');
		Assert::assertSame(0, $anD5['dwellTime']['median'], 'Without measurements: median 0');
		Assert::assertSame(true, array_sum(array_column($anD5['dwellTime']['buckets'], 'value')) === 0 && $anD5['dwellTime']['pages'] === [], 'Without measurements: empty buckets and pages');
		Assert::assertSame('–', $anD5['dwellTime']['comparison']['text'], 'Without measurements: comparison –');
		Assert::assertSame(null, Analysis::data($stD5, new Names([], [], []), 30, [])['dwellTime'], 'Without the switch the section is missing entirely (null)');
		array_map('unlink', glob($dirD5 . '/*'));
		rmdir($dirD5);
		Assert::assertSame(['class' => 'up', 'text' => '▲ +25 %'], Comparison::text(15, 12, 25), 'Comparison with base: enough visits → percentage, even for a small median');
		Assert::assertSame(['class' => 'neutral', 'text' => 'not enough data to compare'], Comparison::text(500, 400, 3), 'Comparison with base: too few visits → no percentage, despite a high median');
		Assert::assertSame(['class' => 'up', 'text' => '▲ +18 %'], Comparison::text(118, 100), 'Comparison without base behaves as before (backward compatibility)');
		$dirW = sys_get_temp_dir() . '/kizami-window-' . bin2hex(random_bytes(4));
		mkdir($dirW, 0700);
		$stW = new Store($dirW . '/' . Store::FILE);
		$todayB = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
		$nowFix = $todayB->modify('+20 hours');
		$stW = $stW->atTime($nowFix);
		$utc = fn (string $when) => $todayB->modify($when)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$ins = function (array $row) use ($stW) {
			$stW->write($row + ['session' => 'x']);
		};
		$ins(['created_at' => $utc('10:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'a', 'device' => 'mobile']);
		$ins(['created_at' => $utc('-3 days 15:00'), 'type' => 'page', 'action' => 'view', 'path' => '/menu', 'session' => 'b', 'device' => 'desktop']);
		$ins(['created_at' => $utc('-3 days 15:05'), 'type' => 'redirect', 'action' => 'click', 'path' => '/menu', 'target' => 'call', 'session' => 'b', 'device' => 'desktop']);
		$ins(['created_at' => $utc('-6 days 09:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'c', 'device' => 'mobile']);
		$ins(['created_at' => $utc('-7 days 12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'd']);
		$ins(['created_at' => $utc('-13 days 12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'e']);
		$ins(['created_at' => $utc('-13 days 12:01'), 'type' => 'redirect', 'action' => 'click', 'path' => '/', 'target' => 'route', 'session' => 'e']);
		$ins(['created_at' => $utc('-14 days 12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'f']);
		$w0 = $stW->window(7, 0, $nowFix);
		$w1 = $stW->window(7, 1, $nowFix);
		$berlinZone = new DateTimeZone('Europe/Berlin');
		$utcZone = new DateTimeZone('UTC');
		$w1UntilPlusN = (new DateTimeImmutable($w1['until'], $utcZone))->setTimezone($berlinZone)->modify('+7 days')->setTimezone($utcZone)->format('Y-m-d H:i:s');
		Assert::assertSame($w0['until'], $w1UntilPlusN, 'Previous period end + n days is the end of the current window');
		foreach ([
			['2026-10-28 20:00', '2026-10-28 19:00:00', '2026-10-21 18:00:00'],
			['2027-03-30 20:00', '2027-03-30 18:00:00', '2027-03-23 19:00:00'],
		] as [$fixedNow, $expectUntil, $expectPrevUntil]) {
			$fixedAt = new DateTimeImmutable($fixedNow, $berlinZone);
			$wf0 = Store::window(7, 0, $fixedAt);
			$wf1 = Store::window(7, 1, $fixedAt);
			Assert::assertSame($expectUntil, $wf0['until'], "Fixed $fixedNow (DST): current window ends at the reference time");
			Assert::assertSame($expectPrevUntil, $wf1['until'], "Fixed $fixedNow (DST): previous period ends 7 Berlin days earlier");
			Assert::assertSame($wf0['until'], (new DateTimeImmutable($wf1['until'], $utcZone))->setTimezone($berlinZone)->modify('+7 days')->setTimezone($utcZone)->format('Y-m-d H:i:s'), "Fixed $fixedNow (DST): previous period + 7 days lines up with the current window");
		}
		Assert::assertSame(3, $stW->visits(7), 'Current window: 3 visits');
		Assert::assertSame(2, $stW->visits(7, 1), 'Previous period: 2 visits');
		Assert::assertSame(3, $stW->pageViews(7), 'Page views current');
		Assert::assertSame(1, $stW->clicks(7), 'Clicks current');
		Assert::assertSame(1, $stW->clicks(7, 0, 'call'), 'Clicks per target');
		Assert::assertSame(1, $stW->clicks(7, 1), 'Clicks previous period');
		$ins(['created_at' => $utc('-3 days 15:06'), 'type' => 'redirect', 'action' => 'click', 'path' => '/menu', 'target' => 'route', 'session' => 'b', 'device' => 'desktop']);
		Assert::assertSame(2, $stW->clicks(7), 'Clicks after the second click');
		Assert::assertSame(1, $stW->visitsWithAction(7), 'Visits with an action count sessions, not clicks');
		$perDay = $stW->visitsPerDay(7);
		Assert::assertSame(7, count($perDay), 'Per day: 7 entries ascending');
		Assert::assertSame($todayB->format('Y-m-d'), $perDay[6]['day'], 'Per day: the last one is today');
		Assert::assertSame(1, $perDay[6]['count'], 'Per day: today 1 visit');
		Assert::assertSame(0, $perDay[1]['count'], 'Per day: 5 days ago 0');
		Assert::assertSame(2, $stW->actionsPerDay(7)[3]['count'], 'Actions per day count clicks: 3 days ago 2');
		Assert::assertSame([['target' => 'call', 'count' => 1], ['target' => 'route', 'count' => 1]], $stW->actions(7), 'Actions per target');
		Assert::assertSame([['target' => 'route', 'count' => 1]], $stW->actions(7, 1), 'Actions previous period');
		$grid = $stW->weekGrid(7);
		Assert::assertSame(7, count($grid), 'Grid has 7 rows');
		Assert::assertSame(24, count($grid[0]), 'Grid has 24 columns');
		$wd3 = (int)$todayB->modify('-3 days')->format('N') - 1;
		Assert::assertSame(1, $grid[$wd3][15], 'Grid: one view 3 days ago at 15:00');
		Assert::assertSame(['mobile' => 2, 'tablet' => 0, 'desktop' => 1, 'unknown' => 0], $stW->devices(7), 'Devices: 2 mobile, 1 desktop; previous-period sessions without a class stay unknown');
		Assert::assertSame(['mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'unknown' => 2], $stW->devices(7, 1), 'Devices previous period: all unknown (stored without device)');
		Assert::assertSame([['path' => '/', 'count' => 2]], $stW->viewsPerPage(7, 1), 'Pages previous period only /');
		$dirF1 = sys_get_temp_dir() . '/kizami-window-fix-' . bin2hex(random_bytes(4));
		mkdir($dirF1, 0700);
		$nineAm = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->modify('+9 hours');
		$w0Fix = Store::window(7, 0, $nineAm);
		$w1Fix = Store::window(7, 1, $nineAm);
		$todayFix = $nineAm->setTime(0, 0, 0);
		Assert::assertSame($todayFix->modify('-6 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w0Fix['since'], 'Window: current since = today−6 00:00');
		Assert::assertSame($nineAm->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w0Fix['until'], 'Window: current until = 9 am today');
		Assert::assertSame($todayFix->modify('-13 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w1Fix['since'], 'Window: previous since = today−13 00:00');
		Assert::assertSame($nineAm->modify('-7 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w1Fix['until'], 'Window: previous until = today−7 9 am');
		$stF1 = new Store($dirF1 . '/' . Store::FILE);
		$stF1 = $stF1->atTime($nineAm);
		$utcFix = fn (string $when) => $nineAm->modify($when)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$stF1->write(['created_at' => $utcFix('today 08:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'today8']);
		$stF1->write(['created_at' => $utcFix('-7 days 10:00'), 'type' => 'page', 'action' => 'view', 'path' => '/later', 'session' => 'minus7-10']);
		$stF1->write(['created_at' => $utcFix('-7 days 08:00'), 'type' => 'page', 'action' => 'view', 'path' => '/earlier', 'session' => 'minus7-8']);
		Assert::assertSame(1, $stF1->visits(7), 'Window: event today 8 am counts as current');
		Assert::assertSame(1, $stF1->visits(7, 1), 'Window: today−7 10 am does NOT count, today−7 8 am counts (1 visit together)');
		Assert::assertSame([['path' => '/earlier', 'count' => 1]], $stF1->viewsPerPage(7, 1), 'Window: … and it is the 8 am event (/earlier), not /later');
		array_map('unlink', glob($dirF1 . '/*'));
		rmdir($dirF1);
		$names = new Names(
			['call' => 'Call'],
			['google.com' => 'Google', 'qr' => 'QR cube'],
			['/menu' => 'Menu'],
			fn (string $path) => $path === '/cafe' ? 'Café' : null
		);
		Assert::assertSame('Call', $names->target('call'), 'Target with a name');
		Assert::assertSame('route', $names->target('route'), 'Target without a name stays the key');
		Assert::assertSame('QR cube', $names->source('utm:qr'), 'utm source with a name');
		Assert::assertSame('Campaign “newsletter”', $names->source('utm:newsletter'), 'utm source without a name');
		Assert::assertSame('Google', $names->source('google.com'), 'Host source with a name');
		Assert::assertSame('bing.com', $names->source('www.bing.com'), 'Host source without a name, without www');
		Assert::assertSame('Direct', $names->source(Names::DIRECT), 'Direct source');
		Assert::assertSame('Internal navigation', $names->source(Names::INTERNAL), 'Internal source');
		Assert::assertSame(true, $names->isInternal(Names::INTERNAL), 'Internal is recognised');
		Assert::assertSame('Menu', $names->page('/menu'), 'Page from the configuration');
		Assert::assertSame('Café', $names->page('/cafe'), 'Page from the Kirby title');
		Assert::assertSame('Home', $names->page('/'), 'Home page');
		Assert::assertSame('/x/y', $names->page('/x/y'), 'Unknown page stays the path');
		I18n::setLanguage('de');
		Assert::assertSame('Direkt', $names->source(Names::DIRECT), 'German: direct source');
		Assert::assertSame('Startseite', $names->page('/'), 'German: home page');
		Assert::assertSame('Kampagne „newsletter“', $names->source('utm:newsletter'), 'German: campaign');
		Assert::assertSame(['class' => 'neutral', 'text' => 'zu wenig Vergleichsdaten'], Comparison::text(12, 3), 'German: too few to compare');
		I18n::setLanguage('en');
		Assert::assertSame(['class' => 'neutral', 'text' => '–'], Comparison::text(0, 0), 'Comparison: both zero');
		Assert::assertSame(['class' => 'new', 'text' => 'new'], Comparison::text(5, 0), 'Comparison: new');
		Assert::assertSame(['class' => 'neutral', 'text' => 'not enough data to compare'], Comparison::text(12, 3), 'Comparison: too few');
		Assert::assertSame(['class' => 'up', 'text' => '▲ +18 %'], Comparison::text(118, 100), 'Comparison: up');
		Assert::assertSame(['class' => 'down', 'text' => '▼ −4 %'], Comparison::text(96, 100), 'Comparison: down');
		Assert::assertSame(['class' => 'neutral', 'text' => '±0 %'], Comparison::text(50, 50), 'Comparison: equal');
		Assert::assertSame(['class' => 'up', 'text' => '▲ sharply up'], Comparison::text(2500, 20), 'Comparison: sharp');
		$an = Analysis::data(
			$stW,
			new Names(['call' => 'Call'], [], []),
			7,
			['brand' => 'Test', 'tiles' => ['call', 'route'], 'color' => '#2f4f2a']
		);
		Assert::assertSame(4, count($an['tiles']), 'Analysis: 4 tiles');
		Assert::assertSame(3, $an['tiles'][0]['value'], 'Tile visits');
		Assert::assertSame('not enough data to compare', $an['tiles'][0]['comparison']['text'], 'Tile visits comparison (previous period 2 < 20)');
		Assert::assertSame('Call', $an['tiles'][2]['name'], 'Tile call is called Call');
		Assert::assertSame(1, $an['tiles'][2]['value'], 'Tile call value');
		Assert::assertSame('route', $an['tiles'][3]['name'], 'Tile route without a name');
		$anTileNames = Analysis::data(
			$stW,
			new Names(['call' => 'Call'], [], []),
			7,
			['brand' => 'Test', 'tiles' => ['call', 'route'], 'tileNames' => ['call' => 'Calls'], 'color' => '#2f4f2a']
		);
		Assert::assertSame('Calls', $anTileNames['tiles'][2]['name'], 'Tile 2 is called Calls with tileNames set');
		Assert::assertSame('route', $anTileNames['tiles'][3]['name'], 'Tile without a tileNames entry keeps the target name');
		$dirMon = sys_get_temp_dir() . '/kizami-month-' . bin2hex(random_bytes(4));
		mkdir($dirMon, 0700);
		$stMon = new Store($dirMon . '/' . Store::FILE);
		$stMon->write(['created_at' => '2026-09-15 10:00:00', 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'm1']);
		$anMon = Analysis::data($stMon, new Names([], [], []), 30, ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
		Assert::assertSame('Sep 26', $anMon['longTerm']['months'][0]['name'] ?? null, 'Month formatted readably (Sep 26 instead of 2026-09)');
		I18n::setLanguage('de');
		$anMonDe = Analysis::data($stMon, new Names([], [], []), 30, ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
		I18n::setLanguage('en');
		Assert::assertSame('Sep. 26', $anMonDe['longTerm']['months'][0]['name'] ?? null, 'German month label (Sep. 26)');
		array_map('unlink', glob($dirMon . '/*'));
		rmdir($dirMon);
		Assert::assertSame(7, count($an['tiles'][0]['trend']), 'Trend has 7 points');
		Assert::assertSame('Call', $an['actions'][0]['name'], 'Actions: Call first (alphabetical on a tie)');
		Assert::assertSame('/menu', $an['actions'][0]['pages'][0]['name'], 'Actions: page /menu stays the path');
		Assert::assertSame(33.3, $an['actionRate']['value'], 'Action rate: one visit with two clicks out of three visits → 33.3');
		Assert::assertSame('About 1 in 3 visits leads to an action (previous period: 1 in 2)', $an['actionRate']['text'], 'Action rate sentence counts visits with an action (1 of 3; previous period 1 of 2)');
		I18n::setLanguage('de');
		$anDe = Analysis::data(
			$stW,
			new Names(['call' => 'Anruf'], [], []),
			7,
			['brand' => 'Test', 'tiles' => ['call', 'route'], 'color' => '#2f4f2a']
		);
		I18n::setLanguage('en');
		Assert::assertSame('Etwa jeder 3. Besuch führt zu einer Aktion (Vorperiode: jeder 2.)', $anDe['actionRate']['text'], 'German action rate sentence');
		Assert::assertSame(0, $an['devicesUnknown'], 'Unknown devices in the current window 0');
		Assert::assertSame([7, 17], [count($an['weekGrid']['values']), count($an['weekGrid']['values'][0])], 'Grid 7 × 17');
		Assert::assertSame('6', $an['weekGrid']['columns'][0], 'Grid columns 6..22');
		Assert::assertSame(['name' => 'Phone', 'value' => 2, 'share' => '67 %'], $an['devices'][0], 'Devices: phone first with share');
		Assert::assertSame('Direct', $an['sources'][0]['name'], 'Sources: Direct');
		Assert::assertSame('Home', $an['pages'][0]['name'], 'Pages: home page named');
		Assert::assertSame(5, count($an['shades']), '5 shades');
		Assert::assertSame(true, preg_match('/^\d{4}-\d\d-\d\d$/', $an['from']) === 1 && $an['to'] === $todayB->format('Y-m-d'), 'From/to set');
		$dirL = sys_get_temp_dir() . '/kizami-empty-' . bin2hex(random_bytes(4));
		mkdir($dirL, 0700);
		$empty = Analysis::data(
			new Store($dirL . '/k.sqlite'),
			new Names([], [], []),
			30,
			['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']
		);
		Assert::assertSame(2, count($empty['tiles']), 'Empty: 2 tiles without redirects');
		Assert::assertSame(0.0, $empty['actionRate']['value'], 'Empty: action rate 0');
		Assert::assertSame('–', $empty['tiles'][0]['comparison']['text'], 'Empty: comparison –');
		$emptyHtml = self::renderDashboard($empty);
		Assert::assertSame(true, substr_count($emptyHtml, 'No data yet') >= 5, 'Empty dashboard shows placeholders');
		Assert::assertSame(false, preg_match('/\b(?:NaN|INF)\b/', $emptyHtml) === 1, 'Empty dashboard without NaN or INF');
		array_map('unlink', glob($dirL . '/*'));
		rmdir($dirL);
		Assert::assertSame(7, count($stW->viewsPerDayFilled(7)), 'Views per day gapless');
		Assert::assertSame(1, array_sum(array_column($stW->actionsPerDay(7, 0, 'call'), 'count')), 'Actions per day per target');
		$beforeBoundary = $stW->visits(7);
		$stW->write(['created_at' => $w0['until'], 'type' => 'page', 'action' => 'view', 'session' => 'future']);
		$stW->write(['created_at' => $utc('12:00'), 'type' => 'notfound', 'action' => 'view', 'path' => '/missing', 'session' => 'error', 'device' => 'desktop']);
		Assert::assertSame($beforeBoundary, $stW->visits(7), 'Future and missing pages are absent from visits');
		Assert::assertSame(3, $stW->pageViews(7), 'Future is absent from page views');
		Assert::assertSame(['mobile' => 2, 'tablet' => 0, 'desktop' => 1, 'unknown' => 0], $stW->devices(7), 'Missing page is absent from devices');
		Assert::assertSame([], $stW->notFound(7, 1), 'Missing pages in the previous period empty');
		$stW->write(['created_at' => $utc('12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'a', 'device' => 'mobile', 'referrer' => '(internal)']);
		$extra = Analysis::data($stW, new Names([], [], []), 7, ['targets' => ['mail']]);
		Assert::assertSame(0, array_column($extra['actions'], 'value', 'name')['mail'], 'Configured target without a click is visible');
		Assert::assertSame(true, !in_array('Internal navigation', array_column($extra['sources'], 'name'), true) && in_array('Internal navigation', array_column($extra['sourcesTable'], 'name'), true), 'Internal navigation only in the table');
		array_map('unlink', glob($dirW . '/*'));
		rmdir($dirW);
		restore_error_handler();
	}
}
