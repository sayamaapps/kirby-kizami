<?php

/**
 * Checks the Kizami core without Kirby.
 *   php tests/CoreTest.php
 *
 * Everything here is Kirby-free — session hash, bot filter, cleaning, SQLite,
 * bcrypt check. The hook in index.php only passes values in; that is exactly
 * why the core can be checked without a running Kirby.
 */
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Chart.php';
require_once __DIR__ . '/../src/Comparison.php';
require_once __DIR__ . '/../src/Names.php';
require_once __DIR__ . '/../src/Gaps.php';
require_once __DIR__ . '/../src/Analysis.php';
require_once __DIR__ . '/../src/Capture.php';
require_once __DIR__ . '/../src/Secret.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Access.php';
require_once __DIR__ . '/../src/Redirect.php';
use Kizami\Analysis;
use Kizami\Capture;
use Kizami\Comparison;
use Kizami\I18n;
use Kizami\Names;
use Kizami\Secret;
use Kizami\Store;
use Kizami\Access;
use Kizami\Redirect;
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
/* === Capture: session hash ============================================== */
/* === Capture: bot filter ================================================ */
/* === Capture: DNT / GPC ================================================= */
/* === Capture: cleaning ================================================== */
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
/* === Secret ============================================================= */
// Key rotation limits linkability within the running installation.
// Counter-check for the rotation: the old secret is GONE, not just put
// aside. If it were still in the file, the whole reasoning would collapse.
// A failure may be quiet, but not invisible: without a log entry nobody
// would ever learn why the numbers are missing.
// rotate(): retire a stale key without a counted visit.
// Counter-check for the failure path: as root, permissions do not bite.
// expire(): remove a stale key, never make one (filtered visitors, the CLI job).
// Clean up (never more than our own throwaway file):
/* === Store: schema & writing ============================================ */
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
/* === Store: queries & compaction ======================================== */
// Three views of /services, one of /contact, two sessions, plus an ancient row.
// Five rows in the window: one with only a referrer, two with utm_source
// (one of them WITH a referrer next to it), two with neither.
// The first written row carries referrer AND utm_source. It counts as utm
// here, not as google.com: utm_source wins, because a campaign is the more
// precise answer than the domain it was clicked through. The same row must
// never count twice.
/* === Access: file check (bcrypt) ======================================== */
/* === Redirect: names and targets ======================================== */
// The target may come from a content field (the phone number is maintained
// in ONE place). If the closure throws, the route must not die with it.
// Counter-checks — every one MUST fire:
// The sneakiest one: //foreign.example looks like an own path, but is
// protocol-relative and lands on a foreign host.
/* === Store: redirects =================================================== */
// A page row next to them: it must NOT be counted.
/* A deploy check of the numbers belongs to the site, not to the package
   (for example a check script in the site repo), and is tested there. */
/* === Dashboard: escaping ================================================ */
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
/* === Clean up =========================================================== */
// Only our own throwaway files, never more.
/* === Device class ======================================================= */
/* === Missing pages (404) ================================================ */
// Legacy pre-1.0 schema: German names, no device column,
// CHECK without notfound. The fixture has to stay German — it is what old
// installations have on disk.
// Second open: nothing changes, no errors, rows stay.
// An intermediate legacy state (device column present, notfound missing) is
// migrated as well; device values are kept.
/* === Dwell time: migration, writing, median/buckets ===================== */
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
/* === Window, previous period, dashboard queries ========================= */
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
/* === Window with a fixed reference time (same elapsed time) ============= */
// At 9 am with n=7: current window = from the start of day (today−6 00:00)
// until now (9 am today); previous period moved back by the same 7 days:
// from today−13 00:00 until today−7 09:00. Without this alignment the
// current window would be systematically shorter in the morning than the
// full previous period and show false minus percentages.
// Of the two previous-period events (today−7 10 am, today−7 8 am) only the
// one at 8 may count — the boundary is today−7 9 am, exclusive.
/* === Names and comparison =============================================== */
// German names: the same tokens, translated by translations/de.php.
/* === Analysis =========================================================== */
// tileNames overrides the target name just for the tile (plural, "Calls"
// instead of "Call"); without the key the target name stays.
// Readable month label in the long-term chart ("Sep 26" instead of "2026-09").
// Empty database: nothing may throw.
// The upper boundary is exclusive: count neither following periods nor missing pages.
/* === Summary ============================================================ */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class CoreTest extends TestCase
{
	private static function check(string $what, mixed $expected, mixed $actual): void
	{
	    global $passed, $failures;
	    if ($expected === $actual) {
	        $passed++; Assert::assertTrue(true);
	        return;
	    }
	    $failures[] = sprintf(
	        "  ✗ %s\n      expected: %s\n      actual:   %s",
	        $what,
	        var_export($expected, true),
	        var_export($actual, true)
	    );
	}

	private static function day(string $ymd): DateTimeImmutable
	{
	    return new DateTimeImmutable($ymd, new DateTimeZone('Europe/Berlin'));
	}

	private static function renderDashboard(array $data): string
	{
	    ob_start();
	    include __DIR__ . '/../views/dashboard.php';
	    return (string) ob_get_clean();
	}

	private static function maintenanceCli(array $args): int {
	    $process = proc_open([PHP_BINARY, __DIR__ . '/../maintenance.php', ...$args],
	        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	    fclose($pipes[0]);
	    stream_get_contents($pipes[1]); fclose($pipes[1]);
	    stream_get_contents($pipes[2]); fclose($pipes[2]);
	    return proc_close($process);
	}

	public function testSuite(): void
	{
		global $passed, $failures;
		$logFile = sys_get_temp_dir() . '/kizami-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		$passed = 0;
		$failures = [];
		$h1 = Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', self::day('2026-08-25'));
		self::check('Hash is deterministic for equal inputs',
		    $h1, Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', self::day('2026-08-25')));
		self::check('Hash rotates across the day boundary',
		    false, $h1 === Capture::sessionHash('1.2.3.4', 'Firefox', 'secret', self::day('2026-08-26')));
		self::check('Hash differs for another IP',
		    false, $h1 === Capture::sessionHash('9.9.9.9', 'Firefox', 'secret', self::day('2026-08-25')));
		self::check('Hash differs for another user agent',
		    false, $h1 === Capture::sessionHash('1.2.3.4', 'Chrome', 'secret', self::day('2026-08-25')));
		self::check('No secret, no hash — nothing may be recorded',
		    '', Capture::sessionHash('1.2.3.4', 'Firefox', '', self::day('2026-08-25')));
		self::check('Known bot UA counts as a bot',
		    true, Capture::isBot('Mozilla/5.0 (compatible; Googlebot/2.1)'));
		self::check('Empty UA counts as a bot — no real browser omits it',
		    true, Capture::isBot(''));
		self::check('Normal browser is not a bot',
		    false, Capture::isBot('Mozilla/5.0 (Macintosh) Firefox/130.0'));
		self::check('DNT: 1 suppresses capture',
		    true, Capture::optedOut(['HTTP_DNT' => '1']));
		self::check('Sec-GPC: 1 suppresses capture',
		    true, Capture::optedOut(['HTTP_SEC_GPC' => '1']));
		self::check('Without DNT/GPC the request counts',
		    false, Capture::optedOut(['HTTP_USER_AGENT' => 'Firefox']));
		self::check('Path loses the query string',
		    '/services', Capture::cleanPath('/services?utm_source=google'));
		self::check('Path drops dangerous characters',
		    '/scriptalert(1)/script', Capture::cleanPath('/<script>alert(1)</script>'));
		self::check('Path is capped at 255 characters',
		    255, strlen(Capture::cleanPath('/' . str_repeat('a', 400))));
		self::check('Referrer is reduced to the domain',
		    'google.com', Capture::cleanReferrer('https://google.com/search?q=doctor'));
		self::check('Referrer without a value gives an empty string',
		    '', Capture::cleanReferrer(null));
		self::check('utm value loses special characters',
		    'scriptalert1script', Capture::cleanId('<script>alert(1)</script>'));
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
		    self::check('Referrer host stays as it was: ' . $printable($url), $host, Capture::cleanReferrer($url));
		}
		self::check('Own host stays "(internal)"', '(internal)', Capture::cleanReferrer('https://own.example/x', ['own.example']));
		$notices = [];
		set_error_handler(function (int $severity, string $message) use (&$notices) { $notices[] = $message; return true; });
		$invalidReferrers = [
		    'invalid byte in the host'           => "https://foo\xFF.example/",
		    'truncated sequence at the host end' => "https://foo.example\xC3/",
		];
		foreach ($invalidReferrers as $what => $url) {
		    self::check('Referrer with ' . $what . ' is discarded', '', Capture::cleanReferrer($url));
		}
		$zeroWidth = Capture::cleanReferrer("https://a\u{200B}b.example/");
		self::check('Referrer with a zero-width space in the host: discarded or cleaned, never stored as is', true, in_array($zeroWidth, ['', 'ab.example'], true));
		restore_error_handler();
		self::check('Discarding an invalid referrer raises no PHP notice or deprecation', [], $notices);
		self::check('Referrer: control character is gone', 'foo_bar.example', Capture::cleanReferrer("https://foo\x01bar.example/"));
		self::check('Referrer: <, >, " and \' are removed from the host', 'abcd.example', Capture::cleanReferrer("https://a<b>\"c'd.example/"));
		self::check('Referrer: format character (BOM) is removed from the host', 'ab.example', Capture::cleanReferrer("https://a\u{FEFF}b.example/"));
		self::check('Referrer: nothing left after cleaning gives an empty string', '', Capture::cleanReferrer('https://<>"\'/'));
		$cutReferrer = Capture::cleanReferrer('https://a' . str_repeat("\u{E4}", 150) . '/');
		self::check('Referrer is cut at 100 characters, not in the middle of one', [100, 1], [preg_match_all('/./us', $cutReferrer), preg_match('//u', $cutReferrer)]);
		self::check('Referrer cut keeps whole characters', 'a' . str_repeat("\u{E4}", 99), $cutReferrer);
		foreach ([
		    ['/services?utm_source=google', '/services'],
		    ['/a%FFb', '/a%FFb'],                                   // Kirby keeps percent-encoding: pure ASCII
		    ["/caf\u{E9}", "/caf\u{E9}"],
		    ["/\u{20AC}", "/\u{20AC}"],
		    ["/ok?x=\xFF", '/ok'],                                  // the query is dropped before the check
		    ['/' . str_repeat('a', 255), '/' . str_repeat('a', 254)],
		] as [$path, $expected]) {
		    self::check('Path stays as it was: ' . $printable($path), $expected, Capture::cleanPath($path));
		}
		self::check('Path with an invalid byte is discarded', '', Capture::cleanPath("/a\xFFb"));
		self::check('Path with a truncated sequence is discarded', '', Capture::cleanPath("/a\xE2\x82"));
		self::check('Path of an invalid byte: nothing is "repaired" into U+FFFD', false, str_contains(Capture::cleanPath("/a\xFFb"), "\u{FFFD}"));
		$cutPath = Capture::cleanPath('/' . str_repeat("\u{20AC}", 100));
		self::check('Path is cut at 255 bytes on a character boundary', [1 + 3 * 84, 1], [strlen($cutPath), preg_match('//u', $cutPath)]);
		self::check('Path cut keeps whole characters', '/' . str_repeat("\u{20AC}", 84), $cutPath);
		self::check('Path of 400 ASCII characters still ends at 255 bytes', 255, strlen(Capture::cleanPath('/' . str_repeat('a', 400))));
		self::check('utm value is capped at 50 characters',
		    50, strlen(Capture::cleanId(str_repeat('a', 80))));
		self::check('utm value without content gives an empty string',
		    '', Capture::cleanId(null));
		$tmp = sys_get_temp_dir() . '/kizami-test-' . bin2hex(random_bytes(6));
		$s1 = Secret::get($tmp, '2026-09-20');
		self::check('Secret is not empty', false, $s1 === '');
		self::check('Secret has full length (32 bytes hex)', 64, strlen($s1));
		self::check('Second call on the same day returns the same secret',
		    $s1, Secret::get($tmp, '2026-09-20'));
		self::check('File has mode 0600', '0600', substr(sprintf('%o', fileperms($tmp . '/' . Secret::FILE)), -4));
		$s2 = Secret::get($tmp, '2026-09-21');
		self::check('A different secret on the next day', false, $s1 === $s2);
		self::check('The new secret is the valid one from now on',
		    $s2, Secret::get($tmp, '2026-09-21'));
		self::check('The old secret is no longer in the file',
		    false, str_contains((string) file_get_contents($tmp . '/' . Secret::FILE), $s1));
		$unwritable = '/proc/kizami-does-not-exist';
		self::check('Directory that cannot be created → empty string, no crash',
		    '', Secret::get($unwritable, '2026-09-20'));
		self::check('… and writes a log entry',
		    true, str_contains((string) @file_get_contents($logFile), 'secret directory cannot be created'));
		$rot = sys_get_temp_dir() . '/kizami-rotate-' . bin2hex(random_bytes(6));
		self::check('rotate() with no key file: nothing to do, true', true, Secret::rotate($rot, '2026-09-22'));
		self::check('… and it creates neither directory nor key', false, file_exists($rot));
		mkdir($rot, 0755, true);
		self::check('rotate() in an existing directory without a key: true, no file', [true, false],
		    [Secret::rotate($rot, '2026-09-22'), is_file($rot . '/' . Secret::FILE)]);
		file_put_contents($rot . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		self::check('rotate() replaces a stale key', true, Secret::rotate($rot, '2026-09-22'));
		[$rotDate, $rotValue] = explode("\n", (string) file_get_contents($rot . '/' . Secret::FILE), 2);
		self::check('… the date line is the given day', '2026-09-22', $rotDate);
		self::check('… the value is new and full length', [false, 64], [$rotValue === 'STALE-SECRET-VALUE', strlen($rotValue)]);
		self::check('… and it is the key get() returns now', $rotValue, Secret::get($rot, '2026-09-22'));
		self::check('… file mode stays 0600', '0600', substr(sprintf('%o', fileperms($rot . '/' . Secret::FILE)), -4));
		$before = (string) file_get_contents($rot . '/' . Secret::FILE);
		self::check('rotate() with a current key: true', true, Secret::rotate($rot, '2026-09-22'));
		self::check('… the file is untouched byte for byte', $before, (string) file_get_contents($rot . '/' . Secret::FILE));
		self::check('rotate() leaves no temp files behind', [$rot . '/' . Secret::FILE], glob($rot . '/*'));
		file_put_contents($rot . '/' . Secret::FILE, "garbage without date line");
		self::check('rotate() replaces a file without a date line', [true, '2026-09-22'],
		    [Secret::rotate($rot, '2026-09-22'), explode("\n", (string) file_get_contents($rot . '/' . Secret::FILE))[0]]);
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
		    file_put_contents($rot . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		    chmod($rot, 0555);
		    self::check('rotate() reports false when a stale key cannot be replaced', false, Secret::rotate($rot, '2026-09-23'));
		    self::check('… and the stale key stays (honest failure, not a silent success)', "2010-01-01\nSTALE-SECRET-VALUE", (string) file_get_contents($rot . '/' . Secret::FILE));
		    self::check('… with a log entry', true, str_contains((string) @file_get_contents($logFile), 'secret not writable'));
		    chmod($rot, 0755);
		}
		@unlink($rot . '/' . Secret::FILE);
		@rmdir($rot);
		$exp = sys_get_temp_dir() . '/kizami-expire-' . bin2hex(random_bytes(6));
		self::check('expire() with no directory: true', true, Secret::expire($exp, '2026-09-22'));
		self::check('… and it creates neither directory nor key', false, file_exists($exp));
		mkdir($exp, 0755, true);
		self::check('expire() in an existing directory without a key: true, still no file', [true, false],
		    [Secret::expire($exp, '2026-09-22'), is_file($exp . '/' . Secret::FILE)]);
		file_put_contents($exp . '/' . Secret::FILE, "2026-09-22\nCURRENT-SECRET-VALUE");
		self::check('expire() with a current key: true', true, Secret::expire($exp, '2026-09-22'));
		self::check('… the file is untouched byte for byte', "2026-09-22\nCURRENT-SECRET-VALUE", (string) file_get_contents($exp . '/' . Secret::FILE));
		file_put_contents($exp . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		self::check('expire() removes a stale key', [true, false], [Secret::expire($exp, '2026-09-22'), file_exists($exp . '/' . Secret::FILE)]);
		self::check('… and leaves nothing else behind', [], glob($exp . '/*'));
		file_put_contents($exp . '/' . Secret::FILE, "garbage without date line");
		self::check('expire() removes a file without a date line', [true, false], [Secret::expire($exp, '2026-09-22'), file_exists($exp . '/' . Secret::FILE)]);
		file_put_contents($exp . '/' . Secret::FILE, '');
		self::check('expire() removes an empty file', [true, false], [Secret::expire($exp, '2026-09-22'), file_exists($exp . '/' . Secret::FILE)]);
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
		    file_put_contents($exp . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		    chmod($exp, 0555);
		    self::check('expire() reports false when a stale key cannot be removed', false, Secret::expire($exp, '2026-09-23'));
		    self::check('… and the stale key stays', "2010-01-01\nSTALE-SECRET-VALUE", (string) file_get_contents($exp . '/' . Secret::FILE));
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
		self::check('Schema is complete after creation', [], $st->missingTables());
		self::check('Fresh DB is writable', null, $st->writeProbe());
		$st->write([
		    'type' => 'page', 'action' => 'view', 'path' => '/services',
		    'referrer' => 'google.com', 'utm_source' => 'newsletter',
		    'utm_medium' => '', 'utm_campaign' => '', 'session' => 'hashA',
		]);
		$db = new SQLite3($dbPath);
		$count = (int) $db->querySingle('SELECT COUNT(*) FROM kizami_events');
		self::check('One row was written', 1, $count);
		self::check('The missing field target is stored as an empty string',
		    '', (string) $db->querySingle('SELECT target FROM kizami_events'));
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
		self::check('Writing to a broken schema throws', true, $thrown);
		self::check('missingTables detects the missing schema',
		    ['kizami_events'], $broken->missingTables());
		$roFile = $dbDir . '/read-only.sqlite';
		touch($roFile);
		chmod($roFile, 0444);
		$thrownRo = false;
		try {
		    new Store($roFile);
		} catch (\RuntimeException $e) {
		    $thrownRo = true;
		}
		self::check('Schemaless read-only DB throws RuntimeException', true, $thrownRo);
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
		self::check('Most viewed page is /services', '/services', $pages[0]['path']);
		self::check('/services has four views (including the row written above)', 4, $pages[0]['count']);
		self::check('Visits count distinct sessions (within 30 days: A and B)',
		    2, $st->visits(30));
		$perDay = $st->viewsPerDay(30);
		self::check('Views per day return exactly one day (everything is from today)', 1, count($perDay));
		self::check('And count all five rows of today', 5, $perDay[0]['count']);
		$sources = array_column($st->sources(30), 'count', 'source');
		self::check('Sources know the referrer domain', 1, $sources['google.com'] ?? 0);
		self::check('With referrer AND utm, utm wins — the same row counts only once',
		    2, $sources['utm:newsletter'] ?? 0);
		self::check('Sources count the rest as direct', 2, $sources[Names::DIRECT] ?? 0);
		self::check('The sum is the number of views in the window, nothing twice',
		    5, array_sum($sources));
		$deleted = $st->compact();
		self::check('Compaction removes the row older than the retention (13 months)', 1, $deleted);
		$db4 = new SQLite3($dbPath);
		$rest = (int) $db4->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path='/ancient'");
		$db4->close();
		self::check('The ancient row is gone', 0, $rest);
		$hash  = password_hash('secret123', PASSWORD_DEFAULT);
		$creds = ['user' => 'client', 'hash' => $hash];
		self::check('Right user + right password → granted',
		    true, Access::checkFile($creds, 'client', 'secret123'));
		self::check('Right user + wrong password → denied',
		    false, Access::checkFile($creds, 'client', 'wrong'));
		self::check('Wrong user → denied',
		    false, Access::checkFile($creds, 'stranger', 'secret123'));
		self::check('Missing configuration → denied (fail closed, unlike a preview guard)',
		    false, Access::checkFile(null, 'client', 'secret123'));
		self::check('Empty hash → denied',
		    false, Access::checkFile(['user' => 'client', 'hash' => ''], 'client', 'secret123'));
		self::check('Missing input → denied',
		    false, Access::checkFile($creds, null, null));
		self::check('Name is lower-cased and cleaned',
		    'call', Redirect::cleanName('Call'));
		self::check('Special characters are dropped from the name',
		    'call', Redirect::cleanName('ca<>ll'));
		self::check('Hyphen and underscore stay',
		    'plan-route_2', Redirect::cleanName('Plan-Route_2'));
		self::check('Name is capped at 50 characters',
		    50, strlen(Redirect::cleanName(str_repeat('a', 80))));
		self::check('Null gives an empty string', '', Redirect::cleanName(null));
		self::check('A string target is passed through',
		    'tel:+4980317', Redirect::resolveTarget('tel:+4980317'));
		self::check('A closure target is called',
		    'tel:+4980317', Redirect::resolveTarget(fn () => 'tel:+4980317'));
		self::check('Throwing closure gives an empty string instead of a crash',
		    '', Redirect::resolveTarget(function () { throw new \RuntimeException('broken'); }));
		self::check('… and logs why the target is missing',
		    true, str_contains((string) @file_get_contents($logFile), 'redirect target cannot be resolved'));
		self::check('Array target gives an empty string', '', Redirect::resolveTarget(['tel:1']));
		self::check('tel: is allowed', true, Redirect::targetAllowed('tel:+4980317'));
		self::check('mailto: is allowed', true, Redirect::targetAllowed('mailto:farm@example.org'));
		self::check('https: is allowed', true, Redirect::targetAllowed('https://example.org/x'));
		self::check('Own path is allowed', true, Redirect::targetAllowed('/menu'));
		self::check('javascript: is forbidden', false, Redirect::targetAllowed('javascript:alert(1)'));
		self::check('data: is forbidden', false, Redirect::targetAllowed('data:text/html,<script>'));
		self::check('http: without TLS is forbidden', false, Redirect::targetAllowed('http://example.org'));
		self::check('Protocol-relative (//host) is forbidden',
		    false, Redirect::targetAllowed('//foreign.example/phishing'));
		self::check('Empty target is forbidden', false, Redirect::targetAllowed(''));
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
		self::check('Two origin pages grouped', 2, count($r));
		self::check('Most clicked origin comes first', '/cafe', $r[0]['path']);
		self::check('With the right count', 2, $r[0]['count']);
		self::check('And the right target', 'call', $r[0]['target']);
		$stX = new Store(':memory:');
		$stX->write(['type' => 'page', 'action' => 'view', 'path' => '/<script>alert(1)</script>', 'referrer' => 'utm:"><script>evil</script>']);
		for ($i = 0; $i < 7; $i++) {
		    $stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'ca<script>ll</script>', 'path' => '/']);
		}
		$html = self::renderDashboard(Analysis::data($stX, new Names([], [], []), 30, []));
		self::check('Dashboard escapes the path — no raw <script>',
		    false, str_contains($html, '<script>alert(1)</script>'));
		self::check('Dashboard escapes the source — no raw <script>',
		    false, str_contains($html, '<script>evil</script>'));
		self::check('Dashboard escapes the redirect target — no raw <script>',
		    false, str_contains($html, '<script>ll</script>'));
		self::check('Escaped markup is present (proof that the value was rendered at all)',
		    true, str_contains($html, '&lt;script&gt;'));
		self::check('No inline event handler in the output (CSP break)',
		    false, str_contains(strtolower($html), 'onclick='));
		self::check('The redirect count is in the output',
		    true, str_contains($html, '>7<'));
		$stBad = new Store(':memory:');
		$stBad->write(['type' => 'page', 'action' => 'view', 'path' => "/a\xFFb", 'referrer' => "foo\xFF.example", 'session' => 'bad', 'device' => 'desktop']);
		$stBad->write(['type' => 'notfound', 'action' => 'view', 'path' => "/missing\xFFpage", 'session' => 'bad2']);
		$htmlBad = self::renderDashboard(Analysis::data($stBad, new Names([], [], []), 30, []));
		self::check('Dashboard shows an invalid source with U+FFFD, not empty',
		    true, str_contains($htmlBad, "foo\u{FFFD}.example"));
		self::check('Dashboard shows an invalid page path with U+FFFD, not empty',
		    true, str_contains($htmlBad, "/a\u{FFFD}b"));
		self::check('Dashboard shows an invalid missing-page path with U+FFFD',
		    true, str_contains($htmlBad, "<td>/missing\u{FFFD}page</td>"));
		self::check('Dashboard output stays valid UTF-8 with invalid stored bytes',
		    1, preg_match('//u', $htmlBad));
		$unavailable = self::renderDashboard(['days' => 30, 'error' => true, 'brand' => 'Test']);
		self::check('No setup message by default',
		    1, substr_count($unavailable, 'class="warning"'));
		self::check('Stylesheet defaults to the domain root',
		    true, str_contains($unavailable, '<link rel="stylesheet" href="/k/dashboard.css">'));
		$unavailable = self::renderDashboard(['days' => 30, 'error' => true, 'brand' => 'Test', 'setup' => 'Kizami: storage root /a&b/<x> is "bad"', 'cssUrl' => 'https://example.invalid/sub/k/dashboard.css']);
		self::check('Setup message is shown escaped under the warning',
		    true, str_contains($unavailable, '<p class="warning">Kizami: storage root /a&amp;b/&lt;x&gt; is &quot;bad&quot;</p>'));
		self::check('Setup message is never printed raw',
		    false, str_contains($unavailable, '<x>'));
		self::check('Stylesheet URL from the data package',
		    true, str_contains($unavailable, '<link rel="stylesheet" href="https://example.invalid/sub/k/dashboard.css">'));
		$unavailable = self::renderDashboard(['days' => 30, 'error' => true, 'brand' => 'Test', 'cssUrl' => '"><script>x</script>']);
		self::check('Stylesheet URL is escaped',
		    false, str_contains($unavailable, '<script>x'));
		$stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'call', 'path' => '/']);
		$stX->write(['type' => 'redirect', 'action' => 'click', 'target' => 'route', 'path' => '/contact']);
		$html = self::renderDashboard(Analysis::data($stX, new Names(['call' => 'Call'], [], []), 30, []));
		self::check('Redirect with a readable name', true, str_contains($html, '<td>Call</td>'));
		self::check('Without a name the internal name stays', true, str_contains($html, '<td>route</td>'));
		self::check('"/" is called Home', true, str_contains($html, 'Home'));
		I18n::setLanguage('de');
		$htmlDe = self::renderDashboard(Analysis::data($stX, new Names(['call' => 'Anruf'], [], []), 30, []));
		I18n::setLanguage('en');
		self::check('German dashboard: "/" is called Startseite', true, str_contains($htmlDe, 'Startseite'));
		self::check('German dashboard: html lang is de', true, str_contains($htmlDe, '<html lang="de">'));
		self::check('Array utm is ignored', '', Capture::cleanId(['test']));
		self::check('Nested path is ignored', '', Capture::cleanPath([['x']]));
		self::check('Internal referrer stays separate from direct access', '(internal)',
		    Capture::cleanReferrer('https://EXAMPLE.org./x', ['example.org']));
		self::check('Foreign referrer stays external', 'other.org',
		    Capture::cleanReferrer('https://other.org/x', ['example.org']));
		self::check('Array in the access file is rejected', false,
		    Access::checkFile(['user' => [], 'hash' => []], 'x', 'x'));
		$dst = new Store($dbDir . '/dst.sqlite');
		$dstDb = new SQLite3($dbDir . '/dst.sqlite');
		foreach (['2025-03-29 23:30:00', '2025-03-30 22:30:00', '2025-10-25 22:30:00', '2025-10-26 23:30:00'] as $time) {
		    $dstDb->exec("INSERT INTO kizami_events (type, action, created_at) VALUES ('page','view','$time')");
		}
		$daysDst = array_column($dst->viewsPerDay(3650), 'count', 'day');
		foreach (['2025-03-30', '2025-03-31', '2025-10-26', '2025-10-27'] as $date) {
		    self::check('Berlin day grouping including daylight saving time: ' . $date, 1, $daysDst[$date] ?? 0);
		}
		$dstDb->exec("INSERT INTO kizami_events (type, created_at) VALUES ('page','2010-01-01 00:00:00')");
		$dst->write(['type' => 'page', 'action' => 'view']);
		self::check('A new view compacts without the dashboard', 0,
		    $dstDb->querySingle("SELECT count(*) FROM kizami_events WHERE created_at < '2011'"));
		$dst->snapshot($dbDir . '/snapshot.sqlite');
		self::check('SQLite snapshot without WAL mode and side files', [1, false, false],
		    [ord(file_get_contents($dbDir . '/snapshot.sqlite', false, null, 18, 1)), is_file($dbDir . '/snapshot.sqlite-wal'), is_file($dbDir . '/snapshot.sqlite-shm')]);
		$backup = new SQLite3($dbDir . '/snapshot.sqlite');
		self::check('SQLite snapshot is consistent', 'ok', $backup->querySingle('PRAGMA integrity_check'));
		self::check('SQLite snapshot contains the WAL data', $dstDb->querySingle('SELECT count(*) FROM kizami_events'),
		    $backup->querySingle('SELECT count(*) FROM kizami_events'));
		$backup->close();
		$dstDb->close();
		$throws = false;
		try { $dst->snapshot($dbDir . '/snapshot.sqlite'); } catch (RuntimeException) { $throws = true; }
		self::check('Snapshot does not overwrite an existing file', true, $throws);
		self::check('Maintenance CLI compacts successfully', 0, self::maintenanceCli(['compact', $dbDir]));
		self::check('Maintenance CLI without a key file creates none', [0, false], [self::maintenanceCli(['compact', $dbDir]), is_file($dbDir . '/' . Secret::FILE)]);
		file_put_contents($dbDir . '/' . Secret::FILE, "2010-01-01\nSTALE-SECRET-VALUE");
		self::check('Maintenance CLI with a stale key succeeds and removes it', [0, false], [self::maintenanceCli(['compact', $dbDir]), is_file($dbDir . '/' . Secret::FILE)]);
		$cliBefore = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d') . "\nCURRENT-SECRET-VALUE";
		file_put_contents($dbDir . '/' . Secret::FILE, $cliBefore);
		self::maintenanceCli(['compact', $dbDir]);
		self::check('Maintenance CLI leaves a current key alone', $cliBefore, (string) file_get_contents($dbDir . '/' . Secret::FILE));
		@unlink($dbDir . '/' . Secret::FILE);
		self::check('Maintenance CLI snapshots successfully', 0, self::maintenanceCli(['snapshot', $dbDir, $dbDir . '/cli.sqlite']));
		$restoreDir = $dbDir . '/restore';
		mkdir($restoreDir, 0700);
		copy($dbDir . '/cli.sqlite', $restoreDir . '/' . Store::FILE);
		$restoredDb = new SQLite3($restoreDir . '/' . Store::FILE);
		self::check('Offline restore passes integrity_check', 'ok', $restoredDb->querySingle('PRAGMA integrity_check'));
		self::check('Offline restore contains representative page rows', 4,
		    $restoredDb->querySingle("SELECT count(*) FROM kizami_events WHERE path='/services'"));
		$restoredDb->close();
		$restored = new Store($restoreDir . '/' . Store::FILE);
		self::check('Offline restore shows the same page counts', $st->viewsPerPage(30), $restored->viewsPerPage(30));
		$restored->write(['type' => 'redirect', 'action' => 'click', 'path' => '/restore-test', 'target' => 'call']);
		self::check('Offline restore can write new clicks', '/restore-test', $restored->redirects(30)[0]['path']);
		unset($restored);
		array_map('unlink', glob($restoreDir . '/*') ?: []);
		rmdir($restoreDir);
		self::check('Maintenance CLI refuses a missing database', 1, self::maintenanceCli(['compact', $dbDir . '/not-there']));
		self::check('Maintenance CLI refuses an existing snapshot', 1, self::maintenanceCli(['snapshot', $dbDir, $dbDir . '/cli.sqlite']));
		array_map('unlink', glob($dbDir . '/*') ?: []);
		@rmdir($dbDir);
		@unlink($logFile);
		$ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
		$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
		$androidPhone = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
		$androidTablet = 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
		$desktop = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';
		self::check('iPad is a tablet', 'tablet', Capture::deviceClass($ipad));
		self::check('iPhone is mobile', 'mobile', Capture::deviceClass($iphone));
		self::check('Android with Mobile is mobile', 'mobile', Capture::deviceClass($androidPhone));
		self::check('Android without Mobile is a tablet', 'tablet', Capture::deviceClass($androidTablet));
		self::check('Mac is desktop', 'desktop', Capture::deviceClass($desktop));
		self::check('Empty is desktop', 'desktop', Capture::deviceClass(''));
		$dirG = sys_get_temp_dir() . '/kizami-device-' . bin2hex(random_bytes(4));
		mkdir($dirG, 0700);
		$stG = new Store($dirG . '/' . Store::FILE);
		$stG->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'h1', 'device' => 'mobile']);
		$dbG = new SQLite3($dirG . '/' . Store::FILE);
		self::check('Device class is stored', 'mobile', $dbG->querySingle('SELECT device FROM kizami_events'));
		self::check('Column device is in the schema', true, in_array('device', Store::columns(), true));
		$dbG->close();
		array_map('unlink', glob($dirG . '/*'));
		rmdir($dirG);
		$dirF = sys_get_temp_dir() . '/kizami-notfound-' . bin2hex(random_bytes(4));
		mkdir($dirF, 0700);
		$old = new SQLite3($dirF . '/' . Store::FILE);
		$old->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT)");
		$old->exec("CREATE INDEX idx_ereignisse_pfad ON ereignisse(pfad)");
		$old->exec("CREATE TABLE kennzahlen_tage (tag TEXT PRIMARY KEY NOT NULL, aufrufe INTEGER NOT NULL, klicks INTEGER NOT NULL, geraete INTEGER NOT NULL)");
		$old->exec("INSERT INTO ereignisse (typ,aktion,pfad,sitzung) VALUES ('seite','aufruf','/old','hashOld')");
		$old->close();
		$stF = new Store($dirF . '/' . Store::FILE);
		$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/does-not-exist', 'session' => 'h1']);
		$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/does-not-exist', 'session' => 'h2']);
		$stF->write(['type' => 'notfound', 'action' => 'view', 'path' => '/also-missing', 'session' => 'h1']);
		$dbF = new SQLite3($dirF . '/' . Store::FILE);
		self::check('Migration keeps old rows', 1, $dbF->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path='/old'"));
		self::check('Migration adds device', '', $dbF->querySingle("SELECT device FROM kizami_events WHERE path='/old'"));
		self::check('Path index exists after the migration', 1, $dbF->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='idx_kizami_events_path'"));
		$dbF->close();
		$stF2 = new Store($dirF . '/' . Store::FILE);
		$dbF = new SQLite3($dirF . '/' . Store::FILE);
		self::check('Opening again does not migrate again (4 rows)', 4, $dbF->querySingle('SELECT COUNT(*) FROM kizami_events'));
		$dbF->close();
		$dirZ = sys_get_temp_dir() . '/kizami-intermediate-' . bin2hex(random_bytes(4));
		mkdir($dirZ, 0700);
		$mid = new SQLite3($dirZ . '/' . Store::FILE);
		$mid->exec("CREATE TABLE ereignisse (id INTEGER PRIMARY KEY AUTOINCREMENT, erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
  typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung')), aktion TEXT CHECK (aktion IN ('aufruf','klick')),
  pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT, geraet TEXT NOT NULL DEFAULT '')");
		$mid->exec("CREATE TABLE kennzahlen_tage (tag TEXT PRIMARY KEY NOT NULL, aufrufe INTEGER NOT NULL, klicks INTEGER NOT NULL, geraete INTEGER NOT NULL)");
		$mid->exec("INSERT INTO ereignisse (typ,aktion,pfad,sitzung,geraet) VALUES ('seite','aufruf','/','h','tablet')");
		$mid->close();
		$stZ = new Store($dirZ . '/' . Store::FILE);
		$stZ->write(['type' => 'notfound', 'action' => 'view', 'path' => '/x', 'session' => 'h']);
		$mid = new SQLite3($dirZ . '/' . Store::FILE);
		self::check('Intermediate state: device value is kept', 'tablet', $mid->querySingle("SELECT device FROM kizami_events WHERE path='/'"));
		$mid->close();
		array_map('unlink', glob($dirZ . '/*'));
		rmdir($dirZ);
		$notFound = $stF->notFound(30);
		self::check('Missing pages: most frequent first', ['path' => '/does-not-exist', 'count' => 2], $notFound[0]);
		self::check('Missing pages do not count as page views', 1, count($stF->viewsPerPage(30)));
		self::check('Missing pages do not count as visits', 1, $stF->visits(30));
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
		self::check('Migration to duration/seconds keeps the existing row', ['id' => 7, 'path' => '/old', 'device' => 'mobile', 'position' => 'header', 'seconds' => 0],
		    $dbD1->query('SELECT id,path,device,position,seconds FROM kizami_events')->fetchArray(SQLITE3_ASSOC));
		$stD1b = new Store($dirD1 . '/' . Store::FILE);
		self::check('Opening again does not migrate again', 1, $dbD1->querySingle('SELECT COUNT(*) FROM kizami_events'));
		$thrownDuration = false;
		try {
		    $stD1->write(['type' => 'duration', 'action' => 'visible', 'path' => '/x', 'session' => 's', 'seconds' => 12]);
		} catch (\Throwable) {
		    $thrownDuration = true;
		}
		self::check('type=duration is allowed by the CHECK after the migration', false, $thrownDuration);
		self::check('The written duration row carries its seconds', 12, (int) $dbD1->querySingle("SELECT seconds FROM kizami_events WHERE type='duration'"));
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
		self::check('Duration row has its seconds', 37, (int) $dbD2->querySingle("SELECT seconds FROM kizami_events WHERE type='duration' AND path='/contact'"));
		self::check('Page row stays at seconds=0 (default)', 0, (int) $dbD2->querySingle("SELECT seconds FROM kizami_events WHERE type='page'"));
		$dbD2->close();
		self::check('Duration does not count as a page view', 1, $stD2->pageViews(30));
		self::check('A duration-only session does not count as a visit', 1, $stD2->visits(30));
		self::check('Duration does not count as a click', 0, $stD2->clicks(30));
		array_map('unlink', glob($dirD2 . '/*'));
		rmdir($dirD2);
		$dirD3 = sys_get_temp_dir() . '/kizami-dwell-median-' . bin2hex(random_bytes(4));
		mkdir($dirD3, 0700);
		$stD3 = new Store($dirD3 . '/' . Store::FILE);
		foreach ([['a', 5], ['b', 15], ['c', 45], ['d', 200]] as [$session, $sec]) {
		    $stD3->write(['type' => 'duration', 'action' => 'visible', 'path' => '/menu', 'session' => $session, 'seconds' => $sec]);
		}
		$anD3 = Analysis::data($stD3, new Names([], [], []), 30, ['dwellTime' => true]);
		self::check('Median of 4 values (5,15,45,200): mean of the two middle ones (15+45)/2', 30, $anD3['dwellTime']['median']);
		$buckets = array_column($anD3['dwellTime']['buckets'], 'value', 'name');
		self::check('Bucket "under 10 s" contains 5 s', 1, $buckets['under 10 s']);
		self::check('Bucket "10–30 s" contains 15 s', 1, $buckets['10–30 s']);
		self::check('Bucket "30 s–1 min" contains 45 s', 1, $buckets['30 s–1 min']);
		self::check('Bucket "1–3 min" is empty', 0, $buckets['1–3 min']);
		self::check('Bucket "over 3 min" contains 200 s', 1, $buckets['over 3 min']);
		$pagesD3 = array_column($anD3['dwellTime']['pages'], null, 'name');
		self::check('Per page: /menu has 4 measurements', 4, $pagesD3['/menu']['measurements']);
		self::check('Per page: the /menu median equals the overall median (only page)', 30, $pagesD3['/menu']['median']);
		self::check('First measurement is set', true, $anD3['dwellTime']['firstMeasurement'] !== null);
		I18n::setLanguage('de');
		$bucketsDe = array_column(Analysis::data($stD3, new Names([], [], []), 30, ['dwellTime' => true])['dwellTime']['buckets'], 'value', 'name');
		I18n::setLanguage('en');
		self::check('German bucket "über 3 Min" contains 200 s', 1, $bucketsDe['über 3 Min'] ?? null);
		array_map('unlink', glob($dirD3 . '/*'));
		rmdir($dirD3);
		$dirD4 = sys_get_temp_dir() . '/kizami-dwell-median-odd-' . bin2hex(random_bytes(4));
		mkdir($dirD4, 0700);
		$stD4 = new Store($dirD4 . '/' . Store::FILE);
		foreach ([['a', 10], ['b', 20], ['c', 30]] as [$session, $sec]) {
		    $stD4->write(['type' => 'duration', 'action' => 'visible', 'path' => '/', 'session' => $session, 'seconds' => $sec]);
		}
		$anD4 = Analysis::data($stD4, new Names([], [], []), 30, ['dwellTime' => true]);
		self::check('Median of 3 values (10,20,30) is the middle value', 20, $anD4['dwellTime']['median']);
		array_map('unlink', glob($dirD4 . '/*'));
		rmdir($dirD4);
		$dirD5 = sys_get_temp_dir() . '/kizami-dwell-empty-' . bin2hex(random_bytes(4));
		mkdir($dirD5, 0700);
		$stD5 = new Store($dirD5 . '/' . Store::FILE);
		$anD5 = Analysis::data($stD5, new Names([], [], []), 30, ['dwellTime' => true]);
		self::check('Without measurements: firstMeasurement is null', null, $anD5['dwellTime']['firstMeasurement']);
		self::check('Without measurements: median 0', 0, $anD5['dwellTime']['median']);
		self::check('Without measurements: empty buckets and pages', true, array_sum(array_column($anD5['dwellTime']['buckets'], 'value')) === 0 && $anD5['dwellTime']['pages'] === []);
		self::check('Without measurements: comparison –', '–', $anD5['dwellTime']['comparison']['text']);
		self::check('Without the switch the section is missing entirely (null)', null,
		    Analysis::data($stD5, new Names([], [], []), 30, [])['dwellTime']);
		array_map('unlink', glob($dirD5 . '/*'));
		rmdir($dirD5);
		self::check('Comparison with base: enough visits → percentage, even for a small median',
		    ['class' => 'up', 'text' => '▲ +25 %'], Comparison::text(15, 12, 25));
		self::check('Comparison with base: too few visits → no percentage, despite a high median',
		    ['class' => 'neutral', 'text' => 'not enough data to compare'], Comparison::text(500, 400, 3));
		self::check('Comparison without base behaves as before (backward compatibility)',
		    ['class' => 'up', 'text' => '▲ +18 %'], Comparison::text(118, 100));
		$dirW = sys_get_temp_dir() . '/kizami-window-' . bin2hex(random_bytes(4));
		mkdir($dirW, 0700);
		$stW = new Store($dirW . '/' . Store::FILE);
		$todayB = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
		$nowFix = $todayB->modify('+20 hours');
		$stW->setNow($nowFix);
		$utc = fn (string $when) => $todayB->modify($when)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$ins = function (array $row) use ($stW) { $stW->write($row + ['session' => 'x']); };
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
		self::check('Previous period end + n days is the end of the current window', $w0['until'], $w1UntilPlusN);
		foreach ([
		    ['2026-10-28 20:00', '2026-10-28 19:00:00', '2026-10-21 18:00:00'],
		    ['2027-03-30 20:00', '2027-03-30 18:00:00', '2027-03-23 19:00:00'],
		] as [$fixedNow, $expectUntil, $expectPrevUntil]) {
		    $fixedAt = new DateTimeImmutable($fixedNow, $berlinZone);
		    $wf0 = Store::window(7, 0, $fixedAt);
		    $wf1 = Store::window(7, 1, $fixedAt);
		    self::check("Fixed $fixedNow (DST): current window ends at the reference time", $expectUntil, $wf0['until']);
		    self::check("Fixed $fixedNow (DST): previous period ends 7 Berlin days earlier", $expectPrevUntil, $wf1['until']);
		    self::check("Fixed $fixedNow (DST): previous period + 7 days lines up with the current window", $wf0['until'], (new DateTimeImmutable($wf1['until'], $utcZone))->setTimezone($berlinZone)->modify('+7 days')->setTimezone($utcZone)->format('Y-m-d H:i:s'));
		}
		self::check('Current window: 3 visits', 3, $stW->visits(7));
		self::check('Previous period: 2 visits', 2, $stW->visits(7, 1));
		self::check('Page views current', 3, $stW->pageViews(7));
		self::check('Clicks current', 1, $stW->clicks(7));
		self::check('Clicks per target', 1, $stW->clicks(7, 0, 'call'));
		self::check('Clicks previous period', 1, $stW->clicks(7, 1));
		$ins(['created_at' => $utc('-3 days 15:06'), 'type' => 'redirect', 'action' => 'click', 'path' => '/menu', 'target' => 'route', 'session' => 'b', 'device' => 'desktop']);
		self::check('Clicks after the second click', 2, $stW->clicks(7));
		self::check('Visits with an action count sessions, not clicks', 1, $stW->visitsWithAction(7));
		$perDay = $stW->visitsPerDay(7);
		self::check('Per day: 7 entries ascending', 7, count($perDay));
		self::check('Per day: the last one is today', $todayB->format('Y-m-d'), $perDay[6]['day']);
		self::check('Per day: today 1 visit', 1, $perDay[6]['count']);
		self::check('Per day: 5 days ago 0', 0, $perDay[1]['count']);
		self::check('Actions per day count clicks: 3 days ago 2', 2, $stW->actionsPerDay(7)[3]['count']);
		self::check('Actions per target', [['target' => 'call', 'count' => 1], ['target' => 'route', 'count' => 1]], $stW->actions(7));
		self::check('Actions previous period', [['target' => 'route', 'count' => 1]], $stW->actions(7, 1));
		$grid = $stW->weekGrid(7);
		self::check('Grid has 7 rows', 7, count($grid));
		self::check('Grid has 24 columns', 24, count($grid[0]));
		$wd3 = (int) $todayB->modify('-3 days')->format('N') - 1;
		self::check('Grid: one view 3 days ago at 15:00', 1, $grid[$wd3][15]);
		self::check('Devices: 2 mobile, 1 desktop; previous-period sessions without a class stay unknown', ['mobile' => 2, 'tablet' => 0, 'desktop' => 1, 'unknown' => 0], $stW->devices(7));
		self::check('Devices previous period: all unknown (stored without device)', ['mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'unknown' => 2], $stW->devices(7, 1));
		self::check('Pages previous period only /', [['path' => '/', 'count' => 2]], $stW->viewsPerPage(7, 1));
		$dirF1 = sys_get_temp_dir() . '/kizami-window-fix-' . bin2hex(random_bytes(4));
		mkdir($dirF1, 0700);
		$nineAm = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->modify('+9 hours');
		$w0Fix = Store::window(7, 0, $nineAm);
		$w1Fix = Store::window(7, 1, $nineAm);
		$todayFix = $nineAm->setTime(0, 0, 0);
		self::check('Window: current since = today−6 00:00',
		    $todayFix->modify('-6 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w0Fix['since']);
		self::check('Window: current until = 9 am today',
		    $nineAm->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w0Fix['until']);
		self::check('Window: previous since = today−13 00:00',
		    $todayFix->modify('-13 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w1Fix['since']);
		self::check('Window: previous until = today−7 9 am',
		    $nineAm->modify('-7 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $w1Fix['until']);
		$stF1 = new Store($dirF1 . '/' . Store::FILE);
		$stF1->setNow($nineAm);
		$utcFix = fn (string $when) => $nineAm->modify($when)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$stF1->write(['created_at' => $utcFix('today 08:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'today8']);
		$stF1->write(['created_at' => $utcFix('-7 days 10:00'), 'type' => 'page', 'action' => 'view', 'path' => '/later', 'session' => 'minus7-10']);
		$stF1->write(['created_at' => $utcFix('-7 days 08:00'), 'type' => 'page', 'action' => 'view', 'path' => '/earlier', 'session' => 'minus7-8']);
		self::check('Window: event today 8 am counts as current', 1, $stF1->visits(7));
		self::check('Window: today−7 10 am does NOT count, today−7 8 am counts (1 visit together)',
		    1, $stF1->visits(7, 1));
		self::check('Window: … and it is the 8 am event (/earlier), not /later',
		    [['path' => '/earlier', 'count' => 1]], $stF1->viewsPerPage(7, 1));
		array_map('unlink', glob($dirF1 . '/*'));
		rmdir($dirF1);
		$names = new Names(
		    ['call' => 'Call'],
		    ['google.com' => 'Google', 'qr' => 'QR cube'],
		    ['/menu' => 'Menu'],
		    fn (string $path) => $path === '/cafe' ? 'Café' : null
		);
		self::check('Target with a name', 'Call', $names->target('call'));
		self::check('Target without a name stays the key', 'route', $names->target('route'));
		self::check('utm source with a name', 'QR cube', $names->source('utm:qr'));
		self::check('utm source without a name', 'Campaign “newsletter”', $names->source('utm:newsletter'));
		self::check('Host source with a name', 'Google', $names->source('google.com'));
		self::check('Host source without a name, without www', 'bing.com', $names->source('www.bing.com'));
		self::check('Direct source', 'Direct', $names->source(Names::DIRECT));
		self::check('Internal source', 'Internal navigation', $names->source(Names::INTERNAL));
		self::check('Internal is recognised', true, $names->isInternal(Names::INTERNAL));
		self::check('Page from the configuration', 'Menu', $names->page('/menu'));
		self::check('Page from the Kirby title', 'Café', $names->page('/cafe'));
		self::check('Home page', 'Home', $names->page('/'));
		self::check('Unknown page stays the path', '/x/y', $names->page('/x/y'));
		I18n::setLanguage('de');
		self::check('German: direct source', 'Direkt', $names->source(Names::DIRECT));
		self::check('German: home page', 'Startseite', $names->page('/'));
		self::check('German: campaign', 'Kampagne „newsletter“', $names->source('utm:newsletter'));
		self::check('German: too few to compare', ['class' => 'neutral', 'text' => 'zu wenig Vergleichsdaten'], Comparison::text(12, 3));
		I18n::setLanguage('en');
		self::check('Comparison: both zero', ['class' => 'neutral', 'text' => '–'], Comparison::text(0, 0));
		self::check('Comparison: new', ['class' => 'new', 'text' => 'new'], Comparison::text(5, 0));
		self::check('Comparison: too few', ['class' => 'neutral', 'text' => 'not enough data to compare'], Comparison::text(12, 3));
		self::check('Comparison: up', ['class' => 'up', 'text' => '▲ +18 %'], Comparison::text(118, 100));
		self::check('Comparison: down', ['class' => 'down', 'text' => '▼ −4 %'], Comparison::text(96, 100));
		self::check('Comparison: equal', ['class' => 'neutral', 'text' => '±0 %'], Comparison::text(50, 50));
		self::check('Comparison: sharp', ['class' => 'up', 'text' => '▲ sharply up'], Comparison::text(2500, 20));
		$an = Analysis::data($stW, new Names(['call' => 'Call'], [], []), 7,
		    ['brand' => 'Test', 'tiles' => ['call', 'route'], 'color' => '#2f4f2a']);
		self::check('Analysis: 4 tiles', 4, count($an['tiles']));
		self::check('Tile visits', 3, $an['tiles'][0]['value']);
		self::check('Tile visits comparison (previous period 2 < 20)', 'not enough data to compare', $an['tiles'][0]['comparison']['text']);
		self::check('Tile call is called Call', 'Call', $an['tiles'][2]['name']);
		self::check('Tile call value', 1, $an['tiles'][2]['value']);
		self::check('Tile route without a name', 'route', $an['tiles'][3]['name']);
		$anTileNames = Analysis::data($stW, new Names(['call' => 'Call'], [], []), 7,
		    ['brand' => 'Test', 'tiles' => ['call', 'route'], 'tileNames' => ['call' => 'Calls'], 'color' => '#2f4f2a']);
		self::check('Tile 2 is called Calls with tileNames set', 'Calls', $anTileNames['tiles'][2]['name']);
		self::check('Tile without a tileNames entry keeps the target name', 'route', $anTileNames['tiles'][3]['name']);
		$dirMon = sys_get_temp_dir() . '/kizami-month-' . bin2hex(random_bytes(4));
		mkdir($dirMon, 0700);
		$stMon = new Store($dirMon . '/' . Store::FILE);
		$stMon->write(['created_at' => '2026-09-15 10:00:00', 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'm1']);
		$anMon = Analysis::data($stMon, new Names([], [], []), 30, ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
		self::check('Month formatted readably (Sep 26 instead of 2026-09)',
		    'Sep 26', $anMon['longTerm']['months'][0]['name'] ?? null);
		I18n::setLanguage('de');
		$anMonDe = Analysis::data($stMon, new Names([], [], []), 30, ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
		I18n::setLanguage('en');
		self::check('German month label (Sep. 26)', 'Sep. 26', $anMonDe['longTerm']['months'][0]['name'] ?? null);
		array_map('unlink', glob($dirMon . '/*'));
		rmdir($dirMon);
		self::check('Trend has 7 points', 7, count($an['tiles'][0]['trend']));
		self::check('Actions: Call first (alphabetical on a tie)', 'Call', $an['actions'][0]['name']);
		self::check('Actions: page /menu stays the path', '/menu', $an['actions'][0]['pages'][0]['name']);
		self::check('Action rate: one visit with two clicks out of three visits → 33.3', 33.3, $an['actionRate']['value']);
		self::check('Action rate sentence counts visits with an action (1 of 3; previous period 1 of 2)', 'About 1 in 3 visits leads to an action (previous period: 1 in 2)', $an['actionRate']['text']);
		I18n::setLanguage('de');
		$anDe = Analysis::data($stW, new Names(['call' => 'Anruf'], [], []), 7,
		    ['brand' => 'Test', 'tiles' => ['call', 'route'], 'color' => '#2f4f2a']);
		I18n::setLanguage('en');
		self::check('German action rate sentence', 'Etwa jeder 3. Besuch führt zu einer Aktion (Vorperiode: jeder 2.)', $anDe['actionRate']['text']);
		self::check('Unknown devices in the current window 0', 0, $an['devicesUnknown']);
		self::check('Grid 7 × 17', [7, 17], [count($an['weekGrid']['values']), count($an['weekGrid']['values'][0])]);
		self::check('Grid columns 6..22', '6', $an['weekGrid']['columns'][0]);
		self::check('Devices: phone first with share', ['name' => 'Phone', 'value' => 2, 'share' => '67 %'], $an['devices'][0]);
		self::check('Sources: Direct', 'Direct', $an['sources'][0]['name']);
		self::check('Pages: home page named', 'Home', $an['pages'][0]['name']);
		self::check('5 shades', 5, count($an['shades']));
		self::check('From/to set', true, preg_match('/^\d{4}-\d\d-\d\d$/', $an['from']) === 1 && $an['to'] === $todayB->format('Y-m-d'));
		$dirL = sys_get_temp_dir() . '/kizami-empty-' . bin2hex(random_bytes(4));
		mkdir($dirL, 0700);
		$empty = Analysis::data(new Store($dirL . '/k.sqlite'), new Names([], [], []), 30,
		    ['brand' => 'Test', 'tiles' => [], 'color' => '#2f4f2a']);
		self::check('Empty: 2 tiles without redirects', 2, count($empty['tiles']));
		self::check('Empty: action rate 0', 0.0, $empty['actionRate']['value']);
		self::check('Empty: comparison –', '–', $empty['tiles'][0]['comparison']['text']);
		$emptyHtml = self::renderDashboard($empty);
		self::check('Empty dashboard shows placeholders', true, substr_count($emptyHtml, 'No data yet') >= 5);
		self::check('Empty dashboard without NaN or INF', false, preg_match('/\b(?:NaN|INF)\b/', $emptyHtml) === 1);
		array_map('unlink', glob($dirL . '/*'));
		rmdir($dirL);
		self::check('Views per day gapless', 7, count($stW->viewsPerDayFilled(7)));
		self::check('Actions per day per target', 1, array_sum(array_column($stW->actionsPerDay(7, 0, 'call'), 'count')));
		$beforeBoundary = $stW->visits(7);
		$stW->write(['created_at' => $w0['until'], 'type' => 'page', 'action' => 'view', 'session' => 'future']);
		$stW->write(['created_at' => $utc('12:00'), 'type' => 'notfound', 'action' => 'view', 'path' => '/missing', 'session' => 'error', 'device' => 'desktop']);
		self::check('Future and missing pages are absent from visits', $beforeBoundary, $stW->visits(7));
		self::check('Future is absent from page views', 3, $stW->pageViews(7));
		self::check('Missing page is absent from devices', ['mobile' => 2, 'tablet' => 0, 'desktop' => 1, 'unknown' => 0], $stW->devices(7));
		self::check('Missing pages in the previous period empty', [], $stW->notFound(7, 1));
		$stW->write(['created_at' => $utc('12:00'), 'type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'a', 'device' => 'mobile', 'referrer' => '(internal)']);
		$extra = Analysis::data($stW, new Names([], [], []), 7, ['targets' => ['mail']]);
		self::check('Configured target without a click is visible', 0, array_column($extra['actions'], 'value', 'name')['mail']);
		self::check('Internal navigation only in the table', true, !in_array('Internal navigation', array_column($extra['sources'], 'name'), true) && in_array('Internal navigation', array_column($extra['sourcesTable'], 'name'), true));
		array_map('unlink', glob($dirW . '/*'));
		rmdir($dirW);
		$this->assertSame([], $failures, implode("
", $failures));
	}
}
