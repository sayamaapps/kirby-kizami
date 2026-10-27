<?php

/**
 * Short report: period limits (only completed Berlin days, the last second
 * counts, today doesn't), an explicit day via "date", comparison period,
 * text in English and German — and the route /k/report with real Kirby:
 * double switch, login only with an allowed role, site identifier.
 *   ddev exec php tests/ReportTest.php
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\I18n;
// Kirby logs failed logins via error_log (auth.debug). The intended failed
// attempts below would otherwise end up as a stack trace in the test output;
// instead this checks THAT they are logged.
// Route with real Kirby, all mutable data in a throwaway directory.
// The first app has the report switched on: Kirby loads index.php only once
// per process, and only then does the role exist (see index.php, 'blueprints').

use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Report;
use Sayamaapps\Kizami\ReportService;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Names::class)]
#[CoversClass(\Sayamaapps\Kizami\Report::class)]
#[CoversClass(\Sayamaapps\Kizami\ReportService::class)]
#[CoversClass(\Sayamaapps\Kizami\SnapshotResponse::class)]
#[CoversClass(\Sayamaapps\Kizami\SnapshotService::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class ReportTest extends TestCase
{
	private static function cleanup(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			if ($file->isDir() && !$file->isLink()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($path);
	}

	public function testSuite(): void
	{
		$base = dirname(__DIR__);
		require $base . '/vendor/autoload.php';
		require_once $base . '/src/I18n.php';
		require_once $base . '/src/Store.php';
		require_once $base . '/src/Names.php';
		require_once $base . '/src/Events.php';
		require_once $base . '/src/Gaps.php';
		require_once $base . '/src/Report.php';
		$logFile = sys_get_temp_dir() . '/kizami-report-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		register_shutdown_function(fn () => @unlink($logFile));
		$berlin = new DateTimeZone('Europe/Berlin');
		$utc = fn (string $time) => (new DateTimeImmutable($time, $berlin))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$thu = new DateTimeImmutable('2026-10-01 09:00', $berlin);
		$mon = new DateTimeImmutable('2026-09-28 09:00', $berlin);
		$tmp = sys_get_temp_dir() . '/kizami-report-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		$failed = false;
		try {
			// Period: Thursday 01.10. → day = Wed 30.09., week = Mon 21.–Sun 27.09.
			$f = fn ($p) => Store::window($p['days'], 0, $p['now']);
			Assert::assertSame(['since' => $utc('2026-09-30 00:00:00'), 'until' => $utc('2026-10-01 00:00:00')], $f(Report::period('day', $thu)), 'Previous day is exactly one whole day');
			Assert::assertSame(['since' => $utc('2026-09-21 00:00:00'), 'until' => $utc('2026-09-28 00:00:00')], $f(Report::period('week', $mon)), 'On Mondays the previous week');
			Assert::assertSame($f(Report::period('week', $mon)), $f(Report::period('week', $thu)), 'During the week the same last full week');
			$p = Report::period('day', $thu);
			Assert::assertSame(['since' => $utc('2026-09-23 00:00:00'), 'until' => $utc('2026-09-24 00:00:00')], Store::window(1, $p['offset'], $p['now']), 'Day comparison: same weekday one week earlier');
			// DST change (25.10.): the week still ends at midnight Berlin time.
			$p = Report::period('week', new DateTimeImmutable('2026-10-26 09:00', $berlin));
			Assert::assertSame(['since' => $utc('2026-10-19 00:00:00'), 'until' => $utc('2026-10-26 00:00:00')], $f($p), 'Week across the DST change');

			// Explicit day: reference day = the day after, or the Monday after the week.
			$refDay = fn (string|null $date, string $kind = 'day') => Report::referenceDay($kind, $date, $thu)->format('Y-m-d H:i');
			Assert::assertSame('2026-10-01 00:00', $refDay(null), 'Without date: today → previous day as before');
			Assert::assertSame('2026-10-01 00:00', $refDay(''), 'Empty date like none');
			Assert::assertSame('2026-09-28 00:00', $refDay('2026-09-27'), 'Date: reference is the day after');
			Assert::assertSame('2026-09-28 00:00', $refDay('2026-09-21', 'week'), 'Week from Monday');
			Assert::assertSame('2026-09-28 00:00', $refDay('2026-09-27', 'week'), 'Week from Sunday: the same week');
			Assert::assertSame($f(Report::period('day', $thu)), $f(Report::period('day', Report::referenceDay('day', '2026-09-30', $thu))), 'Yesterday explicitly = previous day');
			Assert::assertSame(['since' => $utc('2026-10-25 00:00:00'), 'until' => $utc('2026-10-26 00:00:00')], $f(Report::period('day', Report::referenceDay('day', '2026-10-25', new DateTimeImmutable('2026-11-02 09:00', $berlin)))), 'Day of the DST change has 25 hours');
			self::throws(fn () => Report::referenceDay('day', '2026-10-01', $thu), 'not over yet', 'Today is not over');
			self::throws(fn () => Report::referenceDay('day', '2026-12-24', $thu), 'not over yet', 'Future rejected');
			self::throws(fn () => Report::referenceDay('week', '2026-09-28', $thu), 'not over yet', 'Current week rejected');
			self::throws(fn () => Report::referenceDay('day', '2026-02-30', $thu), 'YYYY-MM-DD', 'Invalid date');
			self::throws(fn () => Report::referenceDay('day', '30.09.2026', $thu), 'YYYY-MM-DD', 'German format rejected');
			self::throws(fn () => Report::referenceDay('day', '2026-9-30', $thu), 'YYYY-MM-DD', 'Without leading zero rejected');
			// Retention: single events from today minus 13 months (01.09.2025).
			// The day report also needs the weekday one week before.
			Assert::assertSame('2025-09-09 00:00', $refDay('2025-09-08'), 'Comparison day lies exactly on the limit');
			self::throws(fn () => Report::referenceDay('day', '2025-09-07', $thu), '13 months', 'Comparison day before the limit');
			Assert::assertSame('2025-09-22 00:00', $refDay('2025-09-15', 'week'), 'Comparison week starts after the limit');
			self::throws(fn () => Report::referenceDay('week', '2025-09-01', $thu), '13 months', 'Comparison week before the limit');

			$store = new Store("$tmp/k.sqlite");
			$put = function (string $time, string $session, array $extra = []) use ($store, $utc) {
				$store->write($extra + ['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => $session,
					'created_at' => $utc($time), 'device' => 'mobile']);
			};
			// Previous day 30.09.
			$put('2026-09-30 10:00:00', 'a', ['referrer' => 'www.google.com']);
			$put('2026-09-30 10:01:00', 'a', ['referrer' => '(internal)', 'path' => '/menu']);
			$put('2026-09-30 10:02:00', 'a', ['type' => 'redirect', 'action' => 'click', 'target' => 'call']);
			$put('2026-09-30 10:03:00', 'a', ['type' => 'redirect', 'action' => 'click', 'target' => 'instagram']);
			$put('2026-09-30 10:04:00', 'a', ['type' => 'duration', 'action' => 'visible', 'seconds' => 40]);
			$put('2026-09-30 23:59:59', 'b', ['type' => 'duration', 'action' => 'visible', 'seconds' => 20]);
			$put('2026-09-30 23:59:59', 'b');
			$put('2026-09-30 00:00:00', 'd', ['utm_source' => 'qr', 'device' => 'desktop']);
			// Not on the previous day: today and the day before yesterday
			$put('2026-10-01 00:00:00', 'c');
			$put('2026-09-29 23:59:59', 'e');
			// Previous week Wed 23.09. (day comparison) and week 21.–27.09.
			$put('2026-09-23 12:00:00', 'f');
			$put('2026-09-21 00:00:00', 'g');
			$put('2026-09-27 23:59:59', 'h', ['device' => 'desktop']);
			$put('2026-09-20 12:00:00', 'j');

			$names = new Names(
				['call' => 'Call', 'route' => 'Route', 'instagram' => 'Instagram'],
				['www.google.com' => 'Google', 'qr' => 'QR code'],
				[],
				fn ($p) => $p === '/menu' ? 'Menu' : null
			);
			$settings = ['brand' => 'Sample Inn', 'tiles' => ['call', 'route'], 'tileNames' => ['call' => 'Calls', 'route' => 'Routes'],
				'contactTargets' => ['call', 'route'], 'dwellTime' => true];

			I18n::setLanguage('en');
			$day = Report::data($store, $names, 'day', $thu, $settings);
			Assert::assertSame(['2026-09-30', '2026-09-30'], [$day['from'], $day['to']], 'Day: date');
			Assert::assertSame([3, 1], [$day['visits'], $day['visitsPrevious']], 'Day: visits incl. first and last second');
			Assert::assertSame([4, 1], [$day['pageViews'], $day['pageViewsPrevious']], 'Day: page views');
			Assert::assertSame([['name' => 'Calls', 'value' => 1], ['name' => 'Routes', 'value' => 0], ['name' => 'Instagram', 'value' => 1]], $day['actions'], 'Day: tiles always, other targets only with a click');
			Assert::assertSame(['Direct' => 1, 'Google' => 1, 'QR code' => 1], $day['sources'], 'Day: source per visit, not per page view');
			Assert::assertSame(['Home' => 3, 'Menu' => 1], $day['pages'], 'Day: most viewed pages');
			Assert::assertSame(30, $day['dwellTime'], 'Day: median time on site');
			Assert::assertSame(<<<TXT
    Sample Inn · Wed 30 Sep

    Visits: 3 (previous Wed: 1)
    Page views: 4 (1)
    Link clicks: Calls 1 · Routes 0 · Instagram 1 (total 2, before 0)
    Sources: Direct 1 · Google 1 · QR code 1
    Most viewed: Home 3 · Menu 1
    Time on site (median): 30 s
    TXT, $day['text'], 'Day: text (English)');
			$earlier = Report::data($store, $names, 'day', Report::referenceDay('day', '2026-09-27', $thu), $settings);
			Assert::assertSame(['2026-09-27', 1, 1], [$earlier['from'], $earlier['visits'], $earlier['visitsPrevious']], 'Earlier day via date: own numbers, compared with Sun 20.09.');

			$week = Report::data($store, $names, 'week', $mon, $settings);
			Assert::assertSame(['2026-09-21', '2026-09-27', 3, 1], [$week['from'], $week['to'], $week['visits'], $week['visitsPrevious']], 'Week: period and visits');
			Assert::assertSame(67, $week['mobileShare'], 'Week: phone share');
			Assert::assertSame(null, $week['dwellTime'], 'Week: no invented duration without a measurement');
			Assert::assertSame(<<<TXT
    Sample Inn · Week 21 Sep–27 Sep

    Visits: 3 (previous week: 1)
    Page views: 3 (1)
    Link clicks: Calls 0 · Routes 0 (total 0, before 0)
    Per day: Mon 1 · Tue 0 · Wed 1 · Thu 0 · Fri 0 · Sat 0 · Sun 1
    Sources: Direct 3
    Most viewed: Home 3
    Phone: 67 % of visits
    TXT, $week['text'], 'Week: text (English)');

			// The same reports in German: only Kizami's own strings change, the
			// configured names (tiles, sources, brand) stay as configured.
			I18n::setLanguage('de');
			Assert::assertSame(<<<TXT
    Sample Inn · Mi 30.09.

    Besuche: 3 (Vorwoche Mi: 1)
    Seitenaufrufe: 4 (1)
    Linkaufrufe: Calls 1 · Routes 0 · Instagram 1 (gesamt 2, vorher 0)
    Herkunft: Direkt 1 · Google 1 · QR code 1
    Meistgesehen: Startseite 3 · Menu 1
    Verweildauer (Median): 30 s
    TXT, Report::data($store, $names, 'day', $thu, $settings)['text'], 'Day: text (German)');
			Assert::assertSame(<<<TXT
    Sample Inn · Woche 21.09.–27.09.

    Besuche: 3 (Vorwoche: 1)
    Seitenaufrufe: 3 (1)
    Linkaufrufe: Calls 0 · Routes 0 (gesamt 0, vorher 0)
    Je Tag: Mo 1 · Di 0 · Mi 1 · Do 0 · Fr 0 · Sa 0 · So 1
    Herkunft: Direkt 3
    Meistgesehen: Startseite 3
    Handy: 67 % der Besuche
    TXT, Report::data($store, $names, 'week', $mon, $settings)['text'], 'Week: text (German)');

			$empty = Report::data($store, $names, 'day', new DateTimeImmutable('2026-09-23 08:00', $berlin), $settings);
			Assert::assertSame("Sample Inn · Di 22.09.\nKeine Besuche erfasst (Vorwoche Di: 0).", $empty['text'], 'Empty day: short message (German)');
			I18n::setLanguage('en');
			Assert::assertSame("Sample Inn · Tue 22 Sep\nNo visits recorded (previous Tue: 0).", Report::data($store, $names, 'day', new DateTimeImmutable('2026-09-23 08:00', $berlin), $settings)['text'], 'Empty day: short message (English)');
			for ($i = 0; $i < 3; $i++) {
				$put('2026-09-30 11:00:00', 'scanner-' . $i, ['type' => 'redirect', 'action' => 'click', 'target' => 'call']);
			}
			I18n::setLanguage('de');
			$scan = Report::data($store, $names, 'day', $thu, $settings);
			Assert::assertSame([3, 1, 5, 3], [$scan['visits'], $scan['visitsPrevious'], $scan['clicks'], $scan['directLinkHits']], 'Link scanners stay separate from visits and the previous-week comparison in the report');
			Assert::assertSame(true, str_contains($scan['text'], 'Davon ohne vorherigen Seitenbesuch: 3'), 'Direct hits listed separately in the bot text');
			$put('2026-09-22 11:00:00', 'scanner-only', ['type' => 'redirect', 'action' => 'click', 'target' => 'call']);
			$scanEmpty = Report::data($store, $names, 'day', new DateTimeImmutable('2026-09-23 08:00', $berlin), $settings);
			Assert::assertSame("Sample Inn · Di 22.09.\nKeine Besuche erfasst (Vorwoche Di: 0).\nDirekte Linkaufrufe ohne Seitenbesuch: 1", $scanEmpty['text'], 'Pure link checks create no visits even in the empty day report');
			I18n::setLanguage('en');
			Assert::assertSame(true, str_contains(Report::data($store, $names, 'day', $thu, $settings)['text'], 'Of these without a previous page visit: 3'), 'Direct hits in the English text');

			// Campaigns (utm_campaign, or kizami.campaignParam): own store, so the
			// fixtures above and their texts stay as they are.
			Assert::assertSame([], $day['campaigns'], 'Without tags: no campaigns');
			Assert::assertSame(false, str_contains($day['text'], 'Campaigns:'), 'Without tags: no campaigns line');
			$campaignStore = new Store("$tmp/campaigns.sqlite");
			$tagged = function (string $time, string $session, string $campaign, string $path = '/', string $type = 'page') use ($campaignStore, $utc) {
				$campaignStore->write(['type' => $type, 'action' => $type === 'page' ? 'view' : 'click', 'session' => $session,
					'path' => $path, 'utm_campaign' => $campaign, 'created_at' => $utc($time), 'device' => 'mobile']);
			};
			$tagged('2026-09-30 10:00:00', 's1', 'social-tg-angst');
			$tagged('2026-09-30 10:05:00', 's1', 'social-tg-angst', '/menu');   // second page view of one visit
			$tagged('2026-09-30 11:00:00', 's2', 'social-tg-angst');
			$tagged('2026-09-30 12:00:00', 's3', 'autumn');
			$tagged('2026-09-30 12:01:00', 's4', 'autumn');
			$tagged('2026-09-30 13:00:00', 's6', 'autumn');                     // one visit with two campaigns
			$tagged('2026-09-30 13:01:00', 's6', 'social-tg-angst', '/menu');
			$tagged('2026-09-30 14:00:00', 's7', 'zeta');                       // ties: name decides
			$tagged('2026-09-30 14:01:00', 's8', 'alpha');
			$tagged('2026-09-30 15:00:00', 's9', '');                           // no tag
			$tagged('2026-09-30 15:01:00', 's9', 'social-tg-angst', '/', 'redirect'); // a click, not a page view
			$tagged('2026-09-29 23:59:59', 's10', 'social-tg-angst');           // before the day window
			$tagged('2026-10-01 00:00:00', 's11', 'social-tg-angst');           // after it
			$tagged('2026-09-29 12:00:00', 's12', 'autumn');                    // the day before
			$tagged('2026-09-23 12:00:00', 's13', 'autumn');                    // in the week 21.–27.09.
			// Names translate display labels only; the campaign key stays as it is.
			$campNames = new Names(['social-tg-angst' => 'Telegram'], ['autumn' => 'Herbst'], []);
			$campDay = Report::data($campaignStore, $campNames, 'day', $thu, $settings);
			Assert::assertSame([
				['name' => 'social-tg-angst', 'visits' => 3, 'pageViews' => 4],
				['name' => 'autumn', 'visits' => 3, 'pageViews' => 3],
				['name' => 'alpha', 'visits' => 1, 'pageViews' => 1],
				['name' => 'zeta', 'visits' => 1, 'pageViews' => 1],
			], $campDay['campaigns'], 'Day: visits and page views per campaign, by visits, page views, name');
			$campLines = explode("\n", $campDay['text']);
			$at = array_search('Campaigns: social-tg-angst 3 · autumn 3 · alpha 1 · zeta 1', $campLines, true);
			Assert::assertSame(true, $at !== false && str_starts_with($campLines[$at - 1], 'Sources: ') && str_starts_with($campLines[$at + 1] ?? '', 'Most viewed: '), 'Day: campaigns line between sources and most viewed');
			I18n::setLanguage('de');
			Assert::assertSame(true, in_array('Kampagnen: social-tg-angst 3 · autumn 3 · alpha 1 · zeta 1', explode("\n", Report::data($campaignStore, $campNames, 'day', $thu, $settings)['text']), true), 'Day: campaigns line in German');
			I18n::setLanguage('en');
			// The same numbers as the store returns them (for an offset window the
			// store is the only way to read them: the report has no previous period
			// for campaigns). The day before has social 1 and autumn 1.
			$campaignStore = $campaignStore->atTime(Report::period('day', $thu)['now']);
			Assert::assertSame([
				['campaign' => 'autumn', 'visits' => 1, 'pageViews' => 1],
				['campaign' => 'social-tg-angst', 'visits' => 1, 'pageViews' => 1],
			], $campaignStore->campaigns(1, 1), 'Store: offset window, same mechanics');
			$campaignStore = $campaignStore->atTime(null);
			$campWeek = Report::data($campaignStore, $campNames, 'week', $mon, $settings);
			Assert::assertSame([['name' => 'autumn', 'visits' => 1, 'pageViews' => 1]], $campWeek['campaigns'], 'Week: only its own days');
			Assert::assertSame(0, $campDay['campaignsOmitted'], 'Day: nothing omitted below the cap');
			// Flood: 30 one-off tags on one day. The list is capped, the rest counted,
			// the text shows the top five and a count.
			$floodStore = new Store("$tmp/flood.sqlite");
			for ($i = 1; $i <= 30; $i++) {
				$floodStore->write(['type' => 'page', 'action' => 'view', 'session' => 'f' . $i, 'path' => '/',
					'utm_campaign' => sprintf('junk-%02d', $i), 'created_at' => $utc('2026-09-30 10:00:00'), 'device' => 'mobile']);
			}
			foreach (['r1', 'r2'] as $s) {
				$floodStore->write(['type' => 'page', 'action' => 'view', 'session' => $s, 'path' => '/',
					'utm_campaign' => 'real', 'created_at' => $utc('2026-09-30 11:00:00'), 'device' => 'mobile']);
			}
			$flood = Report::data($floodStore, $campNames, 'day', $thu, $settings);
			Assert::assertSame(Report::CAMPAIGNS_MAX, count($flood['campaigns']), 'Flood: list capped');
			Assert::assertSame('real', $flood['campaigns'][0]['name'], 'Flood: the real campaign stays on top');
			Assert::assertSame(31 - Report::CAMPAIGNS_MAX, $flood['campaignsOmitted'], 'Flood: the rest is counted');
			Assert::assertSame(true, in_array('Campaigns: real 2 · junk-01 1 · junk-02 1 · junk-03 1 · junk-04 1 · +26 more', explode("\n", $flood['text']), true), 'Flood: text shows the top five and a count');
			I18n::setLanguage('de');
			Assert::assertSame(true, str_contains(Report::data($floodStore, $campNames, 'day', $thu, $settings)['text'], ' · +26 weitere'), 'Flood: German count');
			I18n::setLanguage('en');
		} catch (Throwable $e) {
			throw $e;
		} finally {
			I18n::setLanguage('en');
			self::cleanup($tmp);
		}
		$this->assertFalse($failed ?? false, 'no failure');
		$tmp = sys_get_temp_dir() . '/kizami-report-kirby-' . bin2hex(random_bytes(6));
		try {
			foreach (['public', 'site/plugins', 'site/templates', 'site/config', 'site/accounts', 'content/home', 'content/error', 'storage/kizami'] as $dir) {
				mkdir($tmp . '/' . $dir, 0700, true);
			}
			symlink($base, $tmp . '/site/plugins/kizami');
			file_put_contents($tmp . '/content/site.txt', "Title: Sample Inn\n");
			file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
			file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
			file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
			file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
			$baseOptions = [
				'url' => 'http://example.invalid',
				'kizami.active' => true, 'kizami.report' => true, 'kizami.reportWithoutHttps' => true, 'kizami.snapshot' => true,
				'kizami.redirects' => ['call' => 'tel:+498000000', 'route' => 'https://maps.example/'],
				'kizami.targetNames' => ['call' => 'Call', 'route' => 'Route'],
				'kizami.tileNames' => ['call' => 'Calls', 'route' => 'Routes'],
			];
			$app = fn (array $request = [], array $options = []) => new Kirby\Cms\App([
				'roots' => [
					'index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
					'site' => $tmp . '/site', 'storage' => $tmp . '/storage', 'accounts' => $tmp . '/site/accounts',
				],
				'request' => $request,
				'options' => array_merge($baseOptions, $options),
			]);
			$kirby = $app();
			$kirby->impersonate('kirby');
			// The admin first: Kirby 6 refuses any other role for the first account.
			$kirby->users()->create(['email' => 'admin@example.invalid', 'role' => 'admin', 'password' => 'admin-password-very-long-123']);
			$kirby->users()->create(['email' => 'report@example.invalid', 'role' => 'kizami-report', 'password' => 'report-password-very-long-123']);
			$kirby->users()->create(['email' => 'snapshot@example.invalid', 'role' => 'kizami-snapshot', 'password' => 'report-password-very-long-123']);
			Assert::assertSame(['kizami-report', false], [$kirby->user('report@example.invalid')->role()->name(), $kirby->user('report@example.invalid')->role()->permissions()->for('access', 'panel')], 'Role created, without Panel');

			$store = new Store($tmp . '/storage/kizami/' . Store::FILE);
			$store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'x', 'created_at' => $utc('2026-09-30 12:00:00'), 'device' => 'mobile', 'referrer' => 'www.google.com']);
			$store->write(['type' => 'redirect', 'action' => 'click', 'path' => '/', 'target' => 'call', 'session' => 'x', 'created_at' => $utc('2026-09-30 12:01:00'), 'device' => 'mobile']);
			$store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'y', 'created_at' => $utc('2026-09-23 12:00:00'), 'device' => 'mobile']);
			$store->write(['type' => 'event', 'event' => 'download', 'detail' => 'menu.pdf', 'path' => '/', 'session' => 'x', 'created_at' => $utc('2026-09-30 12:02:00')]);

			$request = function (string $url, string|null $who = 'report@example.invalid', string $pw = 'report-password-very-long-123', array $options = []) use ($app, $thu) {
				$request = ['method' => 'GET', 'url' => $url];
				parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
				$request['query'] = $query;
				if ($who !== null) {
					$request['auth'] = 'Basic ' . base64_encode("$who:$pw");
				}
				$response = ReportService::respond($app($request, $options), $thu);
				return [$response->code(), json_decode($response->body(), true), $response->headers(), $response->body()];
			};
			$url = 'http://example.invalid/k/report';

			// Switches: both required, otherwise Kirby's real 404 (no JSON that would reveal the module).
			[$code, , , $body] = $request($url, options: ['kizami.report' => false]);
			Assert::assertSame([404, 'Fixture 404'], [$code, $body], 'Report off: real 404');
			Assert::assertSame(404, $request($url, options: ['kizami.active' => false])[0], 'Kizami off: real 404 despite report on');
			Assert::assertSame(404, $request($url, options: ['kizami.report' => 'yes'])[0], 'Only true switches on');

			// Login
			[$code, $json, $headers] = $request($url, null);
			Assert::assertSame([401, false, true], [$code, $json['ok'], isset($headers['WWW-Authenticate'])], 'Without login, with login prompt');
			Assert::assertSame(true, str_contains($headers['WWW-Authenticate'] ?? '', 'realm="Kizami"'), 'Realm is Kizami');
			Assert::assertSame(401, $request($url, 'report@example.invalid', 'wrong')[0], 'Wrong password');
			Assert::assertSame(401, $request($url, 'nobody@example.invalid', 'whatever-whatever')[0], 'Unknown account');
			Assert::assertSame(true, str_contains((string)@file_get_contents($logFile), 'nobody@example.invalid'), 'Failed attempt logged');
			[$code, $json] = $request($url, 'admin@example.invalid', 'admin-password-very-long-123');
			Assert::assertSame([403, 'This account may not use this route.'], [$code, $json['error']], 'Admin does not get through');
			Assert::assertSame(200, $request($url, 'admin@example.invalid', 'admin-password-very-long-123', ['kizami.reportRoles' => ['admin']])[0], 'Roles via configuration (counter-check to 403)');
			Assert::assertSame(403, $request($url, options: ['kizami.reportRoles' => ['other-role']])[0], 'Own role not allowed when reconfigured');
			[$code, $json] = $request($url, options: ['kizami.reportWithoutHttps' => false]);
			Assert::assertSame([403, 'HTTPS only.'], [$code, $json['error']], 'Rejected without HTTPS');
			Assert::assertSame(200, $request('https://example.invalid/k/report', options: ['kizami.reportWithoutHttps' => false])[0], 'Accepted over HTTPS');

			// Parameters
			Assert::assertSame(422, $request($url . '?kind=year')[0], 'Unknown kind');
			[$code, $json] = $request($url . '?date=2026-10-01');
			Assert::assertSame([422, '2026-10-01 is not over yet.'], [$code, $json['error']], 'Today rejected');
			Assert::assertSame(422, $request($url . '?date=yesterday')[0], 'Not a date');
			Assert::assertSame(422, $request($url . '?date[]=2026-09-30')[0], 'Date as a list');

			// Success: format, identifier, fields (reader scripts rely on them).
			[$code, $json, $headers] = $request($url);
			Assert::assertSame([200, true, 2, 'example.invalid', 'day', '2026-09-30', '2026-09-30'], [$code, $json['ok'], $json['format'], $json['site'], $json['kind'], $json['from'], $json['to']], 'Previous day without date');
			Assert::assertSame(['no-store', 'noindex, nofollow, noarchive'], [$headers['Cache-Control'] ?? null, $headers['X-Robots-Tag'] ?? null], 'Not cached, not indexed');
			// Maintenance in the report is best effort: a database the process cannot
			// write (as root, permissions do not bite) still gives the report.
			if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
				// The journal files too: with a writable -wal/-shm SQLite still writes.
				$dbFiles = array_filter([$tmp . '/storage/kizami/' . Store::FILE, $tmp . '/storage/kizami/' . Store::FILE . '-wal', $tmp . '/storage/kizami/' . Store::FILE . '-shm'], 'is_file');
				foreach ($dbFiles as $f) {
					chmod($f, 0444);
				}
				[$code, $json] = $request($url);
				foreach ($dbFiles as $f) {
					chmod($f, 0644);
				}
				Assert::assertSame([200, true], [$code, $json['ok'] ?? null], 'Report on a read-only database file: still 200');
				// The skip is logged only when compact() actually tries to write.
				// Writes that touch no rows succeed on some SQLite builds, so the
				// log line is not asserted here.
			}
			// respond() sets the language itself — a site may call it from a route
			// of its own (alias), and a German site must still get German text.
			Assert::assertSame(true, str_starts_with((string)$request($url, options: ['locale' => 'de_DE.utf8'])[1]['text'], 'Sample Inn · Mi 30.09.'), 'German locale: German report text via respond()');
			Assert::assertSame(true, str_starts_with((string)$request($url, options: ['locale' => 'de_DE.utf8', 'kizami.language' => 'en'])[1]['text'], 'Sample Inn · Wed 30 Sep'), 'kizami.language wins over the locale');
			foreach (['generatedAt', 'brand', 'visits', 'visitsPrevious', 'pageViews', 'pageViewsPrevious', 'clicks', 'clicksPrevious', 'directLinkHits', 'actions', 'sources', 'pages', 'dwellTime', 'discardedLinkHits', 'discardedLinkHitsPrevious', 'campaigns', 'campaignsOmitted', 'text'] as $field) {
				Assert::assertSame(true, array_key_exists($field, $json), 'Field ' . $field);
			}
			Assert::assertSame([], $json['campaigns'], 'Campaigns: empty list without tags');
			// Actions (1.4.0): a new field, everything else as before — format stays 2.
			Assert::assertSame([], $json['events'], 'Actions: empty list without kizami.events, even with stored actions');
			Assert::assertSame(false, str_contains($json['text'], 'Actions:'), 'Actions: no text line without kizami.events');
			$events = ['kizami.events' => ['download' => ['label' => ['en' => 'Downloads', 'de' => 'Downloads'], 'detail' => true], 'video_play' => ['label' => 'Video']]];
			[$code, $withEvents, , $eventsBody] = $request($url, options: $events);
			Assert::assertSame([200, 2], [$code, $withEvents['format']], 'Actions: format stays 2');
			Assert::assertSame([
				['key' => 'download', 'name' => 'Downloads', 'count' => 1, 'visits' => 1, 'detail' => true, 'details' => [['name' => 'menu.pdf', 'count' => 1, 'visits' => 1]], 'detailsOmitted' => 0],
				['key' => 'video_play', 'name' => 'Video', 'count' => 0, 'visits' => 0, 'detail' => false, 'details' => [], 'detailsOmitted' => 0],
			], $withEvents['events'], 'Actions: field events with total, visits and details');
			Assert::assertSame(true, str_contains($eventsBody, '"details": []'), 'Actions: an empty detail list stays a JSON list');
			$unchanged = $withEvents;
			unset($unchanged['events'], $unchanged['text'], $unchanged['generatedAt']);
			$plain = $json;
			unset($plain['events'], $plain['text'], $plain['generatedAt']);
			Assert::assertSame($plain, $unchanged, 'Actions: every other field unchanged');
			$lines = explode("\n", $withEvents['text']);
			Assert::assertSame(true, in_array('Actions: Downloads 1', $lines, true), 'Actions: text line with the reported action');
			Assert::assertSame($json['text'], implode("\n", array_filter($lines, fn ($line) => $line !== 'Actions: Downloads 1')), 'Actions: the text gains only that line');
			Assert::assertSame(true, str_contains($request($url)[3], '"campaigns": []'), 'Campaigns: JSON list [] rather than {}');
			Assert::assertSame([1, 1, 1, 0], [$json['visits'], $json['visitsPrevious'], $json['pageViews'], $json['clicks'] - 1], 'Numbers of the previous day and the previous week');
			Assert::assertSame([['name' => 'Calls', 'value' => 1], ['name' => 'Routes', 'value' => 0]], $json['actions'], 'Tiles from the configuration');
			Assert::assertSame(['google.com' => 1], $json['sources'], 'Sources without sourceNames: host without www');
			Assert::assertSame(true, str_starts_with($json['text'], 'Sample Inn · Wed 30 Sep'), 'Text with brand from the site title');
			Assert::assertSame(null, $json['dwellTime'], 'Time on site off: null, not 0');
			Assert::assertSame([0, 0], [$json['discardedLinkHits'], $json['discardedLinkHitsPrevious']], 'Discarded link hits: 0 without entries');
			$store->discard('2026-09-30');
			$store->discard('2026-09-30');
			$store->discard('2026-09-23');
			$store->discard('2026-10-01');
			[$code, $json2] = $request($url);
			Assert::assertSame([2, 1], [$json2['discardedLinkHits'], $json2['discardedLinkHitsPrevious']], 'Discarded link hits: only the report day and its comparison day');
			Assert::assertSame($json['text'], $json2['text'], 'Discarded link hits do not change the text');
			Assert::assertSame(true, str_starts_with($request($url, options: ['kizami.brand' => 'Other Brand'])[1]['text'], 'Other Brand · Wed 30 Sep'), 'Brand from kizami.brand');

			[$code, $json, , $body] = $request($url . '?date=2026-09-27');
			Assert::assertSame([200, '2026-09-27', 0], [$code, $json['from'], $json['visits']], 'Earlier day via date');
			Assert::assertSame(true, str_contains($body, '"sources": {}') && str_contains($body, '"pages": {}'), 'Empty lists as {} instead of []');
			[$code, $json] = $request($url . '?kind=week&date=2026-09-23', options: ['kizami.siteId' => 'sample-inn']);
			Assert::assertSame([200, 'week', '2026-09-21', '2026-09-27', 'sample-inn', 1], [$code, $json['kind'], $json['from'], $json['to'], $json['site'], $json['visits']], 'Week via date, identifier from the configuration');
			Assert::assertSame(7, count($json['perDay']), 'Week: seven days');
			Assert::assertSame(true, array_key_exists('mobileShare', $json), 'Week: field mobileShare');

			// ReportService::json() encodes the body itself. Kirby's
			// Response::json() turns a failed json_encode() into an empty body and
			// keeps the status — a 200 with no JSON, which a reading script cannot tell
			// from a working route. Invalid UTF-8 now becomes U+FFFD; anything else that
			// cannot be encoded is a logged 500 with a fixed JSON body.
			// (json_validate() needs PHP 8.3; the suite also runs on 8.2.)
			$validJson = function (string $body): bool {
				json_decode($body);
				return json_last_error() === JSON_ERROR_NONE;
			};
			$badBytes = ReportService::json(['ok' => true, 'sources' => ["foo\xFF.example" => 1]], 200);
			Assert::assertSame([200, true], [$badBytes->code(), $validJson($badBytes->body())], 'Invalid UTF-8 in the data: 200 and valid JSON');
			Assert::assertSame(["foo\u{FFFD}.example" => 1], json_decode($badBytes->body(), true)['sources'], 'Invalid UTF-8 in the data: substituted by U+FFFD');
			Assert::assertSame(['application/json', 'no-store', 'noindex, nofollow, noarchive'], [$badBytes->type(), $badBytes->headers()['Cache-Control'] ?? null, $badBytes->headers()['X-Robots-Tag'] ?? null], 'Invalid UTF-8: content type and headers kept');
			$logBefore = (string)@file_get_contents($logFile);
			$notEncodable = ReportService::json(['ok' => true, 'x' => NAN], 200);
			Assert::assertSame([500, '{"ok":false,"error":"Internal error."}', true], [$notEncodable->code(), $notEncodable->body(), $validJson($notEncodable->body())], 'Data that cannot be encoded: 500 and a fixed JSON body, never 200 without JSON');
			Assert::assertSame(['application/json', 'no-store', 'noindex, nofollow, noarchive'], [$notEncodable->type(), $notEncodable->headers()['Cache-Control'] ?? null, $notEncodable->headers()['X-Robots-Tag'] ?? null], 'Fallback 500: content type and headers kept');
			$logNew = substr((string)file_get_contents($logFile), strlen($logBefore));
			Assert::assertSame(true, str_contains($logNew, 'Kizami: ') && str_contains($logNew, 'Inf and NaN'), 'Data that cannot be encoded: logged with the json error');
			$plainData = ['ok' => true, 'format' => 2, 'url' => 'a/b/c', 'list' => [], 'object' => new stdClass(), 'nested' => ['n' => 1.5, 'text' => "\u{E4}\u{20AC}"]];
			Assert::assertSame(json_encode($plainData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ReportService::json($plainData, 200)->body(), 'Valid data: body byte for byte as before');
			$unauthorized = ReportService::json(['ok' => false, 'error' => 'Authentication required.'], 401);
			Assert::assertSame([401, 'Basic realm="Kizami", charset="UTF-8"'], [$unauthorized->code(), $unauthorized->headers()['WWW-Authenticate'] ?? null], 'Status and login prompt of a 401 unchanged');

			// Measurement gaps (kizami.gaps, since 1.5.0) through the route: flags, list, text line.
			$plainDay = $request($url)[1];
			[$code, $withGap, , $gapBody] = $request($url, options: ['kizami.gaps' => [['from' => '2026-09-30 00:21', 'to' => '2026-09-30 15:40', 'reason' => 'Server outage']]]);
			Assert::assertSame([200, true, false], [$code, $withGap['gap'], $withGap['gapPrevious']], 'Gaps: the report day is flagged, its comparison is not');
			Assert::assertSame([['from' => '2026-09-30 00:21', 'to' => '2026-09-30 15:40', 'reason' => 'Server outage', 'current' => true, 'previous' => false]], $withGap['gaps'], 'Gaps: field gaps');
			Assert::assertSame('Measurement gap 30 Sep 2026 00:21–15:40: Server outage', explode("\n", $withGap['text'])[1], 'Gaps: text line after the heading');
			Assert::assertSame(true, str_contains($gapBody, '"gap": true') && str_contains($gapBody, '"gaps": [') && str_contains($gapBody, '"current": true'), 'Gaps: JSON keys written');
			Assert::assertSame($plainDay['text'], implode("\n", array_filter(explode("\n", $withGap['text']), fn ($line) => !str_starts_with($line, 'Measurement gap'))), 'Gaps: the text gains only the gap line');
			Assert::assertSame([false, []], [$plainDay['gap'], $plainDay['gaps']], 'Gaps: flag false and empty list without kizami.gaps');
			[, $open] = $request($url, options: ['kizami.gaps' => [['from' => '2026-09-29 12:00', 'reason' => 'Still open']]]);
			Assert::assertSame([true, null, 'Measurement gap since 29 Sep 2026 12:00: Still open'], [$open['gap'], $open['gaps'][0]['to'], explode("\n", $open['text'])[1]], 'Gaps: an open gap runs until the report time');

			Assert::assertSame(true, in_array('k/report', array_column($app()->extensions('routes'), 'pattern'), true), 'Route registered');

			// /k/snapshot: consistent copy for backup jobs without a shell.
			$snapshot = function (string|null $who = 'snapshot@example.invalid', string $pw = 'report-password-very-long-123', array $options = [], string $url = 'http://example.invalid/k/snapshot') use ($app) {
				$request = ['method' => 'GET', 'url' => $url];
				if ($who !== null) {
					$request['auth'] = 'Basic ' . base64_encode("$who:$pw");
				}
				$response = Sayamaapps\Kizami\SnapshotService::respond($app($request, $options));
				return [$response->code(), $response->body(), $response->headers() + ['Content-Type' => $response->type()]];
			};
			Assert::assertSame([404, 'Fixture 404'], array_slice($snapshot(options: ['kizami.snapshot' => false]), 0, 2), 'Snapshot off by default: real 404');
			Assert::assertSame(404, $snapshot(options: ['kizami.snapshot' => true, 'kizami.active' => false])[0], 'Snapshot without active: real 404');
			$on = ['kizami.snapshot' => true];
			Assert::assertSame(401, $snapshot(null, options: $on)[0], 'Snapshot without login');
			Assert::assertSame(403, $snapshot('admin@example.invalid', 'admin-password-very-long-123', $on)[0], 'Snapshot: admin does not get through');
			Assert::assertSame(403, $snapshot('report@example.invalid', 'report-password-very-long-123', $on)[0], 'Snapshot: report account does not get through (own role)');
			Assert::assertSame(['kizami-snapshot', false], [$kirby->user('snapshot@example.invalid')->role()->name(), $kirby->user('snapshot@example.invalid')->role()->permissions()->for('access', 'panel')], 'Snapshot role created, without Panel');
			Assert::assertSame(403, $snapshot(options: $on + ['kizami.reportWithoutHttps' => false])[0], 'Snapshot over HTTPS only');
			Assert::assertSame(403, $snapshot(options: $on + ['kizami.snapshotRoles' => ['admin']])[0], 'Snapshot: own role list');
			// The report route runs maintenance like the dashboard: free pages with
			// leftovers of deleted events are rebuilt away, once authenticated.
			$dbFile = $tmp . '/storage/kizami/' . Store::FILE;
			$leave = function () use ($dbFile) {
				$raw = new SQLite3($dbFile);
				$raw->exec('PRAGMA secure_delete = OFF');
				for ($i = 0; $i < 200; $i++) {
					$raw->exec("INSERT INTO kizami_custom_events (created_at, event, detail, path, session) VALUES ('2010-01-01 10:00:00', 'download', 'EXPIRED_PRIVATE_DETAIL_$i', '/', 'old')");
				}
				$raw->exec('DELETE FROM kizami_custom_events WHERE session = \'old\'');
				$raw->close();
			};
			$probe = function () use ($dbFile) {
				$raw = new SQLite3($dbFile);
				// Other connections of this process stay open, so the WAL is not
				// checkpointed and deleted as it is at the end of a request: force
				// the checkpoint and look at the main file only. The -wal file keeps
				// its old frames until it is deleted or truncated, and TRUNCATE is
				// SQLite 3.8.8+ (older builds fall back to a passive checkpoint).
				$raw->exec('PRAGMA wal_checkpoint(TRUNCATE)');
				$state = [(int)$raw->querySingle('PRAGMA freelist_count'), (int)$raw->querySingle('PRAGMA user_version')];
				$raw->close();
				return [$state, str_contains((string)file_get_contents($dbFile), 'EXPIRED_PRIVATE_DETAIL_')];
			};
			$leave();
			[$freeBefore, $markerBefore] = $probe();
			Assert::assertSame([true, true], [$freeBefore[0] > 0, $markerBefore], 'Report maintenance: counter-check, free pages and leftovers present');
			$request($url, null);
			$request($url, 'report@example.invalid', 'wrong-password-very-long-123');
			Assert::assertSame([$freeBefore, $markerBefore], $probe(), 'Anonymous /k/report leaves the database file alone');
			$request($url);
			$after = $probe();
			Assert::assertSame([0, 1], $after[0], 'Authenticated /k/report rebuilds the file once (no free pages, marked)');
			Assert::assertSame(false, $after[1], 'Authenticated /k/report: leftovers of deleted events gone from the file');

			// The daily key also rotates through the authenticated operator routes,
			// never through anonymous requests.
			$keyFile = $tmp . '/storage/kizami/secret.txt';
			$stale = "2010-01-01\nSTALE-SECRET-VALUE";
			$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
			$keyIsFresh = fn () => explode("\n", (string)file_get_contents($keyFile))[0] === $today && !str_contains((string)file_get_contents($keyFile), 'STALE-SECRET-VALUE');
			file_put_contents($keyFile, $stale);
			$snapshot(null, options: $on);
			$request($url, null);
			$snapshot('report@example.invalid', 'report-password-very-long-123', $on);
			$request($url, 'report@example.invalid', 'wrong-password-very-long-123');
			Assert::assertSame($stale, file_get_contents($keyFile), 'Unauthenticated, forbidden and failed logins leave the key alone');
			$request($url);
			Assert::assertSame(true, $keyIsFresh(), 'Authenticated /k/report replaces a stale key');
			file_put_contents($keyFile, $stale);
			$snapshot(options: $on);
			Assert::assertSame(true, $keyIsFresh(), 'Authenticated /k/snapshot replaces a stale key');
			$currentKey = (string)file_get_contents($keyFile);
			$snapshot(options: $on);
			$request($url);
			Assert::assertSame($currentKey, file_get_contents($keyFile), 'A current key is not touched again');
			unlink($keyFile);
			$request($url);
			$snapshot(options: $on);
			Assert::assertSame(false, is_file($keyFile), 'The routes create no key on an installation that never counted');
			file_put_contents($tmp . '/storage/kizami/secret.txt', "2026-10-01\nSECRET-MARKER");
			[$code, $body, $headers] = $snapshot(options: $on);
			Assert::assertSame([200, 'application/vnd.sqlite3', hash('sha256', $body)], [$code, $headers['Content-Type'] ?? null, $headers['X-Kizami-Sha256'] ?? null], 'Snapshot delivered, with checksum');
			Assert::assertSame(true, str_contains($headers['Content-Disposition'] ?? '', 'filename="kizami-'), 'Snapshot file name kizami-YYYY-MM-DD.sqlite');
			Assert::assertSame(false, str_contains($body, 'SECRET-MARKER'), 'Snapshot without the daily secret');
			// Header byte 18: 1 = rollback journal (one file), 2 = WAL (would need -wal/-shm).
			Assert::assertSame(1, ord($body[18] ?? "\0"), 'Snapshot is a single file, not WAL');
			file_put_contents($tmp . '/copy.sqlite', $body);
			$copy = new SQLite3($tmp . '/copy.sqlite');
			Assert::assertSame(['ok', 3, 4], [$copy->querySingle('PRAGMA integrity_check'), $copy->querySingle('SELECT COUNT(*) FROM kizami_events'), $copy->querySingle('SELECT SUM(hits) FROM kizami_discarded')], 'Snapshot complete and intact');
			Assert::assertSame('download|menu.pdf', $copy->querySingle("SELECT event || '|' || detail FROM kizami_custom_events"), 'Snapshot includes the reported actions');
			$copy->close();
			Assert::assertSame([], glob($tmp . '/storage/kizami/.snapshot-*'), 'Intermediate file deleted');

			// The answer is streamed (SnapshotResponse), not held as a string.
			$storageDir = $tmp . '/storage/kizami';
			$respond = fn () => Sayamaapps\Kizami\SnapshotService::respond($app([
				'method' => 'GET', 'url' => 'http://example.invalid/k/snapshot',
				'auth' => 'Basic ' . base64_encode('snapshot@example.invalid:report-password-very-long-123'),
			], $on));
			// Runs send() like production, but counts and hashes the output in a buffer callback instead of keeping it.
			$stream = function (Kirby\Http\Response $response): array {
				$bytes = 0;
				$context = hash_init('sha256');
				ob_start(function (string $chunk) use (&$bytes, $context): string {
					hash_update($context, $chunk);
					$bytes += strlen($chunk);
					return '';
				}, 65536);
				$returned = $response->send();
				ob_end_flush();
				return [$returned, $bytes, hash_final($context)];
			};
			$streamed = $respond();
			Assert::assertSame(true, $streamed instanceof Sayamaapps\Kizami\SnapshotResponse, 'Snapshot: streamed response class');
			Assert::assertSame([], glob($storageDir . '/.snapshot-*'), 'Snapshot: the copy is already unlinked while the answer is not yet sent');
			$announced = $streamed->headers();
			[$returned, $bytes, $streamedHash] = $stream($streamed);
			Assert::assertSame('', $returned, 'Snapshot: send() has already written the body, returns nothing');
			Assert::assertSame(true, $bytes > 0 && $bytes === (int)($announced['Content-Length'] ?? -1), 'Snapshot: streamed bytes = Content-Length');
			Assert::assertSame($announced['X-Kizami-Sha256'] ?? null, $streamedHash, 'Snapshot: streamed bytes hash to X-Kizami-Sha256');
			Assert::assertSame(hash('sha256', $respond()->body()), $streamedHash, 'Snapshot: streaming and body() deliver the same copy');
			Assert::assertSame([], glob($storageDir . '/.snapshot-*'), 'Snapshot: no file left after send()');
			$reread = $respond();
			$inMemory = $reread->body();
			Assert::assertSame(strlen($inMemory), (int)($reread->headers()['Content-Length'] ?? -1), 'Snapshot: Content-Length = length of the copy');
			Assert::assertSame($inMemory, $reread->body(), 'Snapshot: body() can be read twice');
			Assert::assertSame([true, hash('sha256', $inMemory)], [$stream($reread)[1] === strlen($inMemory), $stream($respond())[2]], 'Snapshot: send() still works after body()');
			unset($streamed, $reread);

			// Leftovers of aborted downloads: removed after an hour, younger ones stay (a parallel download may run).
			$leave = function (string $name, int $age) use ($storageDir) {
				file_put_contents($storageDir . '/' . $name, 'x');
				touch($storageDir . '/' . $name, time() - $age);
			};
			$leave('.snapshot-stale.sqlite', 7200);
			$leave('.snapshot-stale.sqlite-wal', 7200);
			$leave('.snapshot-fresh.sqlite', 0);
			$snapshot(null, options: $on);
			Assert::assertSame([true, true, true], array_map('is_file', [$storageDir . '/.snapshot-stale.sqlite', $storageDir . '/.snapshot-stale.sqlite-wal', $storageDir . '/.snapshot-fresh.sqlite']), 'Snapshot: no clean-up without a login');
			$snapshot(options: $on);
			Assert::assertSame([false, false, true], array_map('is_file', [$storageDir . '/.snapshot-stale.sqlite', $storageDir . '/.snapshot-stale.sqlite-wal', $storageDir . '/.snapshot-fresh.sqlite']), 'Snapshot: stale leftovers swept, fresh ones stay');
			Assert::assertSame([true, true], [is_file($storageDir . '/' . Store::FILE), is_file($storageDir . '/secret.txt')], 'Snapshot: sweep leaves the database and the secret alone');
			unlink($storageDir . '/.snapshot-fresh.sqlite');

			// cleanup() and sweep() directly.
			$dir = $tmp . '/sweep';
			mkdir($dir, 0700);
			$make = function (string $name, int $age) use ($dir) {
				file_put_contents($dir . '/' . $name, 'x');
				touch($dir . '/' . $name, time() - $age);
			};
			// scandir() sorts with the locale (strcoll): under C.UTF-8 a leading dot
			// is ignored, under C it sorts first. Sort by bytes, so the expected
			// lists hold on every runner.
			$listing = function () use ($dir): array {
				$names = array_values(array_diff(scandir($dir), ['.', '..']));
				sort($names, SORT_STRING);
				return $names;
			};
			foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
				$make('.snapshot-gone.sqlite' . $suffix, 0);
			}
			$make('.snapshot-other.sqlite', 0);
			Assert::assertSame(null, Sayamaapps\Kizami\SnapshotService::cleanup($dir . '/.snapshot-gone.sqlite'), 'cleanup() returns nothing');
			Assert::assertSame(['.snapshot-other.sqlite'], $listing(), 'cleanup(): the copy and its side files go, other files stay');
			Assert::assertSame(null, Sayamaapps\Kizami\SnapshotService::cleanup($dir . '/.snapshot-missing.sqlite'), 'cleanup() of a missing copy: no error');
			unlink($dir . '/.snapshot-other.sqlite');
			foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
				$make('.snapshot-old.sqlite' . $suffix, 7200);
			}
			$make('.snapshot-over.sqlite', 3700);
			$make('.snapshot-edge.sqlite', 3500);
			$make('.snapshot-new.sqlite', 60);
			$make(Store::FILE, 7200);
			$make('secret.txt', 7200);
			$make('snapshot-without-dot', 7200);
			mkdir($dir . '/.snapshot-directory');
			Assert::assertSame(5, Sayamaapps\Kizami\SnapshotService::sweep($dir), 'sweep(): returns the number of files removed');
			Assert::assertSame(['.snapshot-directory', '.snapshot-edge.sqlite', '.snapshot-new.sqlite', Store::FILE, 'secret.txt', 'snapshot-without-dot'], $listing(), 'sweep(): only .snapshot-* files older than an hour go');
			Assert::assertSame(0, Sayamaapps\Kizami\SnapshotService::sweep($dir), 'sweep(): nothing more to remove');
			Assert::assertSame(0, Sayamaapps\Kizami\SnapshotService::sweep($dir . '/missing'), 'sweep() of a missing directory: 0');

			// Memory: a copy larger than twice the memory_limit still comes through, in a child
			// process with that limit. The helper boots Kirby plus respond() in ~6.5 MB; the limit
			// leaves about 9 MB of room, a string holding the copy (the old way) needs 40 MB.
			$limitMb = 16;
			$bigStorage = $tmp . '/big-storage';
			mkdir($bigStorage . '/kizami', 0700, true);
			$bigDb = $bigStorage . '/kizami/' . Store::FILE;
			new Store($bigDb);
			$big = new SQLite3($bigDb);
			$big->exec('PRAGMA synchronous = OFF');
			$pageSize = (int)$big->querySingle('PRAGMA page_size');
			$columns = Store::columns();
			$insert = $big->prepare('INSERT INTO kizami_events (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
			$big->exec('BEGIN');
			for ($i = 0; $big->querySingle('PRAGMA page_count') * $pageSize < $limitMb * 2.5 * 1048576;) {
				for ($k = 0; $k < 5000; $k++, $i++) {
					// 255-byte path (it is indexed, so it counts twice), everything else small.
					$row = [gmdate('Y-m-d H:i:s'), 'page', 'view', '/' . substr(str_repeat(sha1((string)$i), 7), 0, 254), null, null, null, null, null, md5((string)$i), 'desktop', '', 0];
					foreach ($row as $c => $value) {
						$insert->bindValue($c + 1, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
					}
					$insert->execute();
				}
			}
			$big->exec('COMMIT');
			$big->exec('PRAGMA wal_checkpoint(TRUNCATE)');
			$big->close();
			Assert::assertSame(true, filesize($bigDb) >= 2 * $limitMb * 1048576, 'Memory test: the database is more than twice the memory limit');
			$child = proc_open([PHP_BINARY, '-d', 'memory_limit=' . $limitMb . 'M', $base . '/tests/helpers/snapshot-stream.php', $tmp, $bigStorage], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$childOut = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$childErr = stream_get_contents($pipes[2]);
			fclose($pipes[2]);
			$childExit = proc_close($child);
			Assert::assertSame(0, $childExit, 'Memory test: child process under memory_limit=' . $limitMb . 'M ends normally: ' . substr(trim($childOut . ' ' . $childErr), 0, 300));
			$result = json_decode(trim($childOut), true);
			Assert::assertSame(true, is_array($result), 'Memory test: child reports JSON: ' . substr($childOut, 0, 300));
			Assert::assertSame([200, 'SQLite format 3', ''], [$result['code'], $result['head'], $result['returned']], 'Memory test: status, SQLite header, send() result');
			Assert::assertSame(true, $result['bytes'] >= 2 * $limitMb * 1048576 && $result['bytes'] === (int)$result['length'], 'Memory test: all bytes of the copy arrive, as many as Content-Length says');
			Assert::assertSame($result['sha256'], $result['hash'], 'Memory test: the bytes that arrive match X-Kizami-Sha256');
			Assert::assertSame(true, $result['peak'] < $result['bytes'] / 2, 'Memory test: peak memory (' . $result['peak'] . ') far below the size of the copy (' . $result['bytes'] . ')');
			Assert::assertSame([], glob($bigStorage . '/kizami/.snapshot-*'), 'Memory test: no file left in the storage');
			Assert::assertSame(true, in_array('k/snapshot', array_column($app()->extensions('routes'), 'pattern'), true), 'Snapshot route registered');
		} catch (Throwable $e) {
			throw $e;
		} finally {
			self::cleanup($tmp);
		}
		$this->assertFalse($failed ?? false, 'no failure');
	}

	private static function throws(callable $f, string $pattern, string $what): void
	{
		try {
			$f();
		} catch (InvalidArgumentException $e) {
			if (!str_contains($e->getMessage(), $pattern)) {
				Assert::fail($what . ': wrong message ' . $e->getMessage());
			}
			Assert::assertTrue(true);
			return;
		}
		throw new RuntimeException($what . ': no exception');
	}
}
