<?php

/**
 * Reported actions (kizami.events, since 1.4.0) without Kirby: the allow
 * list, the cleaning of the detail, the size cap, the table of its own with
 * retention, the numbers, the dashboard section and the report field.
 *   php tests/EventsTest.php
 */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Analysis;
use Sayamaapps\Kizami\Capture;
use Sayamaapps\Kizami\Events;
// Every warning, notice or deprecation is a failure: the cleaning must not
// "work" only because a function returned null on bad input.

use Sayamaapps\Kizami\I18n;
use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Report;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Analysis::class)]
#[CoversClass(\Sayamaapps\Kizami\Capture::class)]
#[CoversClass(\Sayamaapps\Kizami\Events::class)]
#[CoversClass(\Sayamaapps\Kizami\I18n::class)]
#[CoversClass(\Sayamaapps\Kizami\Names::class)]
#[CoversClass(\Sayamaapps\Kizami\Report::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class EventsTest extends TestCase
{
	private static function render(array $data): string
	{
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		return ob_get_clean();
	}

	public function testSuite(): void
	{
		set_error_handler(function (int $level, string $message, string $file, int $line): bool {
			throw new ErrorException($message, 0, $level, $file, $line);
		});
		$tmp = sys_get_temp_dir() . '/kizami-events-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		try {
			// Allow list: only valid keys with an array entry; detail strictly true.
			$config = Events::config([
				'download' => ['label' => ['de' => 'Download', 'en' => 'Download'], 'detail' => true],
				'video_play' => ['label' => ['de' => 'Video gestartet', 'en' => 'Video played']],
				'social-click' => ['label' => 'Social', 'detail' => 'yes'],
				'resume' => [],
				'Download' => ['label' => 'upper case'],
				str_repeat('a', 33) => ['label' => 'too long'],
				str_repeat('b', 32) => ['label' => 'longest'],
				'with space' => ['label' => 'x'],
				'dot.key' => ['label' => 'x'],
				'' => ['label' => 'empty'],
				7 => ['label' => 'number'],
				'plain' => 'not an array',
				'labels' => ['label' => ['de' => '', 'en' => ['x'], 'fr' => 'Étiquette']],
			]);
			Assert::assertSame(['download', 'video_play', 'social-click', 'resume', str_repeat('b', 32), 'labels'], array_keys($config), 'Config: only valid keys with an array entry');
			Assert::assertSame([true, false, false, false], [$config['download']['detail'], $config['video_play']['detail'], $config['social-click']['detail'], $config['resume']['detail']], "Config: detail only with exactly true ('yes' is not true)");
			Assert::assertSame([], Events::config(null), 'Config: not set means empty');
			Assert::assertSame([], Events::config('download'), 'Config: a string is no list');
			Assert::assertSame('Video gestartet', Events::label($config, 'video_play', 'de'), 'Label in the dashboard language');
			Assert::assertSame('Video played', Events::label($config, 'video_play', 'fr'), 'Label falls back to English');
			Assert::assertSame('Social', Events::label($config, 'social-click', 'de'), 'A string label is the English label');
			Assert::assertSame('Étiquette', Events::label($config, 'labels', 'de'), 'Empty and non-string labels are skipped, then the first one');
			Assert::assertSame('resume', Events::label($config, 'resume', 'de'), 'Without a label: the key');
			foreach (['download', 'a', '0-9_x', str_repeat('z', 32)] as $key) {
				Assert::assertSame(true, Events::isKey($key), "Key accepted: $key");
			}
			foreach (['', 'Download', 'down load', 'a.b', 'ä', str_repeat('z', 33), "download\n", ['download'], null, 5] as $key) {
				Assert::assertSame(false, Events::isKey($key), 'Key rejected: ' . json_encode($key));
			}

			// Detail: cleaned, capped, URLs without query and fragment.
			foreach ([
				['Preisliste 2026.pdf', 'Preisliste 2026.pdf', 'plain text unchanged'],
				['  Album „Herbst“  ', 'Album „Herbst“', 'trimmed, Unicode kept'],
				["Zeile eins\nZeile\r\nzwei\tdrei", 'Zeile eins Zeile zwei drei', 'line breaks and tabs become one space'],
				["a\x00b\x1Fc\x7Fd", 'a b c d', 'control characters removed'],
				["Text\u{2028}weiter", 'Text weiter', 'Unicode line separator removed'],
				["ab\u{202E}cd\u{200B}ef", 'abcdef', 'bidi override and zero-width space removed'],
				['<img src=x onerror=alert(1)>', 'img src=x onerror=alert(1)', 'angle brackets removed'],
				['https://example.com/files/a.pdf?token=SECRET#page=2', 'example.com/files/a.pdf', 'URL: host and path, no query, no fragment'],
				['HTTPS://User:Pass@Example.COM:8443/A.pdf?x=1', 'example.com/A.pdf', 'URL: no credentials, no port, host lower case'],
				['https://example.com?ref=SECRET', 'example.com', 'URL without path'],
				['Video (https://youtu.be/abc?si=SECRET) ansehen', 'Video (youtu.be/abc) ansehen', 'URL inside text, closing bracket kept'],
				['Siehe https://x.de/a?b=SECRET.', 'Siehe x.de/a.', 'URL at the end of a sentence'],
				['//cdn.example.com/a.mp3?sig=SECRET', 'cdn.example.com/a.mp3', 'protocol-relative URL'],
				['www.example.com/shop?utm_source=SECRET', 'www.example.com/shop', 'www. without scheme'],
				['/downloads/flyer.pdf?download=SECRET', '/downloads/flyer.pdf', 'site path without query'],
				['/a.pdf#SECRET', '/a.pdf', 'site path without fragment'],
				['ftp://files.example.com/x.zip?SECRET', 'files.example.com/x.zip', 'any scheme'],
				['mailto:anna@example.com?subject=SECRET', 'mailto:', 'mailto: only the scheme'],
				['Anruf tel:+491701234567', 'Anruf tel:', 'tel: only the scheme'],
				['Frage? Ja! und/oder 1/2', 'Frage? Ja! und/oder 1/2', 'question marks and slashes in plain text stay'],
				["Rock 'n' Roll \"Live\"", "Rock 'n' Roll \"Live\"", 'quotes stay (the dashboard escapes)'],
				['https://', '', 'URL without host dropped'],
				['   ', '', 'only spaces → empty'],
				['', '', 'empty'],
			] as [$raw, $expected, $what]) {
				Assert::assertSame($expected, Capture::cleanDetail($raw), 'cleanDetail: ' . $what);
			}
			foreach ([null, 5, ['a'], true, "\xff\xfeabc", "abc\xc3"] as $raw) {
				Assert::assertSame('', Capture::cleanDetail($raw), 'cleanDetail: rejected ' . json_encode(is_string($raw) ? bin2hex($raw) : $raw));
			}
			Assert::assertSame(100, mb_strlen(Capture::cleanDetail(str_repeat('ä', 150))), 'cleanDetail: at most 100 characters, not bytes');
			Assert::assertSame(str_repeat('ä', 100), Capture::cleanDetail(str_repeat('ä', 150)), 'cleanDetail: cut on a character boundary');
			Assert::assertSame(str_repeat('x', 99), Capture::cleanDetail(str_repeat('x', 99) . ' yz'), 'cleanDetail: no trailing space after cutting');
			Assert::assertSame(false, str_contains(Capture::cleanDetail(str_repeat('a', 90) . ' https://x.de/' . str_repeat('p', 20) . '?SECRET'), 'SECRET'), 'cleanDetail: query removed before cutting');

			// Size cap of /k/event.
			Assert::assertSame(false, Capture::requestTooLarge(['CONTENT_LENGTH' => '120'], 2048), 'Size: normal beacon');
			Assert::assertSame(false, Capture::requestTooLarge(['CONTENT_LENGTH' => '2048'], 2048), 'Size: exactly the limit');
			Assert::assertSame(true, Capture::requestTooLarge(['CONTENT_LENGTH' => '2049'], 2048), 'Size: one byte over');
			Assert::assertSame(true, Capture::requestTooLarge(['CONTENT_LENGTH' => '-1'], 2048), 'Size: no number');
			Assert::assertSame(true, Capture::requestTooLarge(['CONTENT_LENGTH' => '1e9'], 2048), 'Size: no plain number');
			Assert::assertSame(false, Capture::requestTooLarge([], 2048), 'Size: no body');
			Assert::assertSame(true, Capture::requestTooLarge(['QUERY_STRING' => str_repeat('a', 2049)], 2048), 'Size: long query string');

			// Table of its own; numbers per key; visits per Berlin day and identifier.
			$store = new Store("$tmp/k.sqlite");
			$db = new SQLite3("$tmp/k.sqlite");
			$berlin = new DateTimeZone('Europe/Berlin');
			$today = new DateTimeImmutable('today', $berlin);
			$store = $store->atTime($today->setTime(12, 0));
			$at = fn (int $day, int $hour = 10) => $today->modify("$day days")->setTime($hour, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$event = function (string $key, string $session, int $day = 0, string $detail = '', int $hour = 10) use ($store, $at) {
				$store->write(['type' => 'event', 'event' => $key, 'detail' => $detail, 'path' => '/', 'session' => $session,
					'created_at' => $at($day, $hour), 'device' => 'mobile', 'target' => 'ignored']);
			};
			$store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'a', 'created_at' => $at(0, 9)]);
			$before = [$store->visits(7), $store->pageViews(7), $store->clicks(7), $store->devices(7), $store->longTerm()];
			$event('download', 'a', 0, 'a.pdf');
			$event('download', 'a', 0, 'a.pdf');      // same visit, same detail: two reports, one visit
			$event('download', 'a', 0, 'b.pdf');
			$event('download', 'b', 0, 'a.pdf');
			$event('download', 'a', -1, 'a.pdf');     // same identifier the day before: a visit of its own
			$event('download', '', 0, 'c.pdf');       // without identifier: counted, but no visit
			$event('video_play', 'b', 0);
			$event('video_play', 'c', -7);            // previous period
			$event('gone', 'a', 0);                   // no longer configured
			Assert::assertSame(0, $db->querySingle("SELECT COUNT(*) FROM kizami_events WHERE type NOT IN ('page')"), 'Actions are not written to kizami_events');
			Assert::assertSame(9, $db->querySingle('SELECT COUNT(*) FROM kizami_custom_events'), 'Actions written to kizami_custom_events');
			Assert::assertSame(['created_at' => $at(0), 'event' => 'download', 'detail' => 'a.pdf', 'path' => '/', 'session' => 'a'], $db->query('SELECT created_at, event, detail, path, session FROM kizami_custom_events ORDER BY id LIMIT 1')->fetchArray(SQLITE3_ASSOC), 'Row: key, detail, page, day identifier — no device, no other fields');
			Assert::assertSame([
				['event' => 'download', 'count' => 6, 'visits' => 3],
				['event' => 'gone', 'count' => 1, 'visits' => 1],
				['event' => 'video_play', 'count' => 1, 'visits' => 1],
			], $store->customEvents(7), 'Total and visits per action (visits per Berlin day, empty identifier not a visit)');
			Assert::assertSame([['event' => 'video_play', 'count' => 1, 'visits' => 1]], $store->customEvents(7, 1), 'Previous period');
			Assert::assertSame([['event' => 'download', 'count' => 5, 'visits' => 2], ['event' => 'gone', 'count' => 1, 'visits' => 1], ['event' => 'video_play', 'count' => 1, 'visits' => 1]], $store->customEvents(1), 'One day');
			Assert::assertSame(['details' => [
				['detail' => 'a.pdf', 'count' => 4, 'visits' => 3],
				['detail' => 'b.pdf', 'count' => 1, 'visits' => 1],
				['detail' => 'c.pdf', 'count' => 1, 'visits' => 0],
			], 'omitted' => 0], $store->customEventDetails('download', 7, 10), 'Details: by count, then name');
			Assert::assertSame(['details' => [['detail' => 'a.pdf', 'count' => 4, 'visits' => 3]], 'omitted' => 2], $store->customEventDetails('download', 7, 1), 'Details: capped, the rest counted');
			Assert::assertSame(['details' => [], 'omitted' => 0], $store->customEventDetails('video_play', 7, 10), 'Details: empty details are no details');
			Assert::assertSame($before, [$store->visits(7), $store->pageViews(7), $store->clicks(7), $store->devices(7), $store->longTerm()], 'Actions change neither visits, page views, clicks, devices nor the long term');

			// Summary for dashboard and report: configured actions only, most reported first.
			I18n::setLanguage('de');
			$summary = Events::summary($store, $config, 7, I18n::language());
			Assert::assertSame(['download', 'video_play', 'social-click', 'resume', str_repeat('b', 32), 'labels'], array_column($summary, 'key'), 'Summary: configured actions, most reported first, ties in configuration order, unconfigured key left out');
			Assert::assertSame(['key' => 'download', 'name' => 'Download', 'count' => 6, 'visits' => 3, 'detail' => true, 'details' => [
				['name' => 'a.pdf', 'count' => 4, 'visits' => 3], ['name' => 'b.pdf', 'count' => 1, 'visits' => 1], ['name' => 'c.pdf', 'count' => 1, 'visits' => 0],
			], 'detailsOmitted' => 0], $summary[0], 'Summary: an action with details');
			Assert::assertSame(['key' => 'video_play', 'name' => 'Video gestartet', 'count' => 1, 'visits' => 1, 'detail' => false, 'details' => [], 'detailsOmitted' => 0], $summary[1], 'Summary: an action without details, German label');
			Assert::assertSame(0, $summary[2]['count'], 'Summary: a configured action without reports is listed with 0');
			$db->exec("INSERT INTO kizami_custom_events (created_at, event, detail, session) VALUES ('" . $at(0) . "', 'video_play', 'stored earlier', 'x')");
			Assert::assertSame([], Events::summary($store, $config, 7, 'de')[1]['details'], 'Summary: details only for actions with detail => true');
			$db->exec("DELETE FROM kizami_custom_events WHERE detail = 'stored earlier'");
			for ($i = 1; $i <= 12; $i++) {
				$event('download', 'flood', 0, sprintf('junk-%02d', $i));
			}
			$flood = Events::summary($store, $config, 7, 'de')[0];
			Assert::assertSame([Events::DETAILS_MAX, 'a.pdf', 5], [count($flood['details']), $flood['details'][0]['name'], $flood['detailsOmitted']], 'Summary: details capped at 10, the most frequent first, the rest counted');
			$db->exec("DELETE FROM kizami_custom_events WHERE session = 'flood'");

			// Dashboard section.
			$names = new Names([], [], []);
			I18n::setLanguage('en');
			$html = self::render(Analysis::data($store, $names, 7, ['events' => $config]));
			preg_match('/<section class="block" aria-labelledby="events-title">.*?<\/section>/s', $html, $section);
			$section = $section[0] ?? '';
			Assert::assertSame(true, str_contains($section, '<h2 id="events-title">Actions</h2>'), 'Dashboard: section "Actions"');
			Assert::assertSame(true, str_contains($section, '<th scope="col" class="num">Total</th><th scope="col" class="num">Visits with this action</th>'), 'Dashboard: total and visits columns');
			Assert::assertSame(true, str_contains($section, '<tr><td>Download</td><td class="num">6</td><td class="num">3</td></tr>'), 'Dashboard: row per action with total and visits');
			Assert::assertSame(true, str_contains($section, '<tr><td>Video played</td><td class="num">1</td><td class="num">1</td></tr>'), 'Dashboard: English label');
			Assert::assertSame(true, str_contains($section, '<h3>Download: most frequent details</h3>') && str_contains($section, '<tr><td>a.pdf</td><td class="num">4</td><td class="num">3</td></tr>'), 'Dashboard: top details below');
			Assert::assertSame(false, str_contains($section, 'Video played: most frequent details'), 'Dashboard: no detail table for actions without details');
			Assert::assertSame(false, str_contains($html, '<script') || (bool)preg_match('/ style="/', $html), 'Dashboard: still no script, no inline style');
			$event('download', 'x', 0, '<b>"&</b>');
			$db->exec("INSERT INTO kizami_custom_events (created_at, event, detail, session) VALUES ('" . $at(0) . "', 'download', '<script>alert(1)</script>', 'x')");
			$html = self::render(Analysis::data($store, $names, 7, ['events' => $config + Events::config(['xss' => ['label' => '<i>Label</i>']])]));
			Assert::assertSame(true, str_contains($html, '&lt;b&gt;&quot;&amp;&lt;/b&gt;') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($html, '&lt;i&gt;Label&lt;/i&gt;') && !str_contains($html, '<script>'), 'Dashboard: details and labels HTML-escaped, even if written past the cleaning');
			$db->exec("DELETE FROM kizami_custom_events WHERE session = 'x'");
			Assert::assertSame(false, str_contains(self::render(Analysis::data($store, $names, 7, [])), 'events-title'), 'Dashboard: no section without kizami.events');
			$quiet = self::render(Analysis::data(new Store("$tmp/quiet.sqlite"), $names, 7, ['events' => $config]));
			Assert::assertSame(true, str_contains($quiet, 'events-title') && str_contains($quiet, 'No reported actions in this period yet'), 'Dashboard: section with a note while nothing was reported');
			for ($i = 1; $i <= 12; $i++) {
				$event('download', 'flood', 0, sprintf('junk-%02d', $i));
			}
			Assert::assertSame(true, str_contains(self::render(Analysis::data($store, $names, 7, ['events' => $config])), '5 more details not shown.'), 'Dashboard: hidden details counted');
			$db->exec("DELETE FROM kizami_custom_events WHERE session = 'flood'");
			I18n::setLanguage('de');
			$htmlDe = self::render(Analysis::data($store, $names, 7, ['events' => $config]));
			Assert::assertSame(true, str_contains($htmlDe, '<h2 id="events-title">Aktionen</h2>') && str_contains($htmlDe, 'Besuche mit dieser Aktion') && str_contains($htmlDe, 'Download: häufigste Zusatzangaben'), 'Dashboard: German');
			I18n::setLanguage('en');

			// Report: field events, a text line only when something was reported.
			$report = new Store("$tmp/report.sqlite");
			$thu = new DateTimeImmutable('2026-10-01 09:00', $berlin);
			$utc = fn (string $time) => (new DateTimeImmutable($time, $berlin))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$report->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => 'r', 'created_at' => $utc('2026-09-30 10:00')]);
			$plain = Report::data($report, $names, 'day', $thu, ['brand' => 'Test']);
			Assert::assertSame([], $plain['events'], 'Report: empty list without kizami.events');
			$configured = Report::data($report, $names, 'day', $thu, ['brand' => 'Test', 'events' => $config]);
			Assert::assertSame($plain['text'], $configured['text'], 'Report: text unchanged while nothing was reported');
			Assert::assertSame(0, array_sum(array_column($configured['events'], 'count')), 'Report: configured actions listed with 0');
			$report->write(['type' => 'event', 'event' => 'download', 'detail' => 'a.pdf', 'path' => '/', 'session' => 'r', 'created_at' => $utc('2026-09-30 10:05')]);
			$report->write(['type' => 'event', 'event' => 'download', 'detail' => 'a.pdf', 'path' => '/', 'session' => 'r', 'created_at' => $utc('2026-09-30 10:06')]);
			$report->write(['type' => 'event', 'event' => 'video_play', 'path' => '/', 'session' => 'r', 'created_at' => $utc('2026-09-30 10:07')]);
			$report->write(['type' => 'event', 'event' => 'video_play', 'path' => '/', 'session' => 'r', 'created_at' => $utc('2026-10-01 08:00')]);  // today: not in the report
			$day = Report::data($report, $names, 'day', $thu, ['brand' => 'Test', 'events' => $config]);
			Assert::assertSame(['key' => 'download', 'name' => 'Download', 'count' => 2, 'visits' => 1, 'detail' => true,
				'details' => [['name' => 'a.pdf', 'count' => 2, 'visits' => 1]], 'detailsOmitted' => 0], $day['events'][0], 'Report: total, visits and details of the day');
			Assert::assertSame(['video_play', 1], [$day['events'][1]['key'], $day['events'][1]['count']], 'Report: only completed days');
			Assert::assertSame(true, in_array('Actions: Download 2 · Video played 1', explode("\n", $day['text']), true), 'Report: text line with the reported actions only');
			$lines = explode("\n", $day['text']);
			Assert::assertSame(array_search('Actions: Download 2 · Video played 1', $lines, true) - 1, array_search('Page views: 1 (0)', $lines, true), 'Report: actions line after the page views (no link clicks that day)');
			I18n::setLanguage('de');
			Assert::assertSame(true, in_array('Aktionen: Download 2 · Video gestartet 1', explode("\n", Report::data($report, $names, 'day', $thu, ['brand' => 'Test', 'events' => $config])['text']), true), 'Report: German line with German labels');
			I18n::setLanguage('en');
			$week = Report::data($report, $names, 'week', new DateTimeImmutable('2026-10-05 09:00', $berlin), ['events' => $config])['events'];
			Assert::assertSame([['download', 2, 1], ['video_play', 2, 2]], array_map(fn ($e) => [$e['key'], $e['count'], $e['visits']], array_slice($week, 0, 2)), 'Report: the week counts all its days, a visit per day');

			// Retention: 13 months, like the single events; not rolled up.
			$old = (new DateTimeImmutable('today', $berlin))->modify('-13 months -1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$recent = (new DateTimeImmutable('today', $berlin))->modify('-13 months +1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$retention = new Store("$tmp/retention.sqlite");
			$retention->write(['type' => 'page', 'action' => 'view', 'session' => 'o', 'created_at' => $old]);
			$retention->write(['type' => 'event', 'event' => 'download', 'detail' => 'old.pdf', 'session' => 'o', 'created_at' => $old]);
			$retention->write(['type' => 'event', 'event' => 'download', 'detail' => 'recent.pdf', 'session' => 'o', 'created_at' => $recent]);
			Assert::assertSame(1, $retention->compact(), 'Retention: compact() still returns the rolled-up single events');
			$rdb = new SQLite3("$tmp/retention.sqlite");
			Assert::assertSame(['recent.pdf'], array_column(iterator_to_array((function () use ($rdb) {
				$r = $rdb->query('SELECT detail FROM kizami_custom_events');
				while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
					yield $row;
				}
			})(), false), 'detail'), 'Retention: actions older than 13 months deleted, younger ones kept');
			Assert::assertSame(['views' => 1, 'clicks' => 0, 'devices' => 1], $rdb->query('SELECT views, clicks, devices FROM kizami_days')->fetchArray(SQLITE3_ASSOC), 'Retention: actions are not part of the daily totals');
			$rdb->close();
			// The daily compaction on write deletes them as well.
			$daily = new Store("$tmp/daily.sqlite");
			$daily->write(['type' => 'event', 'event' => 'download', 'session' => 'o', 'created_at' => $old]);
			$ddb = new SQLite3("$tmp/daily.sqlite");
			$ddb->exec('DELETE FROM kizami_maintenance');
			$daily->write(['type' => 'page', 'action' => 'view', 'session' => 'n']);
			Assert::assertSame(0, $ddb->querySingle('SELECT COUNT(*) FROM kizami_custom_events'), 'Retention: the daily compaction on the first write of a day');
			$ddb->close();

			// A database from 1.3.0 gets the table on the next open, nothing else changes.
			$legacy = new SQLite3("$tmp/legacy.sqlite");
			$legacy->exec(Store::EVENTS_TABLE);
			$legacy->exec('CREATE TABLE kizami_days (day TEXT PRIMARY KEY NOT NULL, views INTEGER NOT NULL, clicks INTEGER NOT NULL, devices INTEGER NOT NULL)');
			$legacy->exec("INSERT INTO kizami_events (type, action, path, session) VALUES ('page', 'view', '/kept', 's')");
			$legacy->close();
			$upgraded = new Store("$tmp/legacy.sqlite");
			$upgraded->write(['type' => 'event', 'event' => 'download', 'session' => 's']);
			$ldb = new SQLite3("$tmp/legacy.sqlite");
			Assert::assertSame([1, 1], [$ldb->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path = '/kept'"), $ldb->querySingle('SELECT COUNT(*) FROM kizami_custom_events')], 'Upgrade from 1.3.0: table created, events kept');
			$ldb->close();

			$db->close();
		} catch (Throwable $e) {
			throw $e;
		} finally {
			unset($store, $report, $retention, $daily, $upgraded);
			foreach (glob("$tmp/*") as $f) {
				unlink($f);
			}
			rmdir($tmp);
		}
		$this->assertFalse($failed ?? false, 'no failure');
		restore_error_handler();
	}
}
