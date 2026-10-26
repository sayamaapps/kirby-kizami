<?php

/**
 * Measurement gaps (kizami.gaps, since 1.5.0) without Kirby: the option
 * validation, the overlap with days and ranges, the period text.
 *   php tests/GapsTest.php
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Analysis;
use Sayamaapps\Kizami\Gaps;
// Every warning or notice is a failure: a bad entry is ignored by design,
// not by accident of a function returning null.
/** Log lines written since the last call; resets the log. */

use Sayamaapps\Kizami\I18n;
use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Report;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Analysis::class)]
#[CoversClass(\Sayamaapps\Kizami\Gaps::class)]
#[CoversClass(\Sayamaapps\Kizami\I18n::class)]
#[CoversClass(\Sayamaapps\Kizami\Names::class)]
#[CoversClass(\Sayamaapps\Kizami\Report::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class GapsTest extends TestCase
{
	private static function logged(): array
	{
		global $log;
		$lines = is_file($log) ? array_values(array_filter(explode("\n", (string)file_get_contents($log)))) : [];
		if (is_file($log)) {
			unlink($log);
		}
		return $lines;
	}

	private static function render(array $data): string
	{
		ob_start();
		include __DIR__ . '/../views/dashboard.php';
		return ob_get_clean();
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
		global $n, $log;
		set_error_handler(function (int $level, string $message, string $file, int $line): bool {
			throw new ErrorException($message, 0, $level, $file, $line);
		});
		$tmp = sys_get_temp_dir() . '/kizami-gaps-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		$log = "$tmp/error.log";
		ini_set('error_log', $log);
		$n = 0;
		$berlin = new DateTimeZone('Europe/Berlin');
		$at = fn (string $time) => new DateTimeImmutable($time, $berlin);
		try {
			// Valid entries: from and to, reason optional, open gap without 'to'.
			$outage = ['from' => '2026-10-13 00:21', 'to' => '2026-10-13 15:40', 'reason' => 'Server outage'];
			self::same([$outage], Gaps::config([$outage]), 'Config: a valid gap is kept as it is');
			self::same(
				[['from' => '2026-10-13 00:21', 'to' => null, 'reason' => '']],
				Gaps::config([['from' => '2026-10-13 00:21']]),
				'Config: missing "to" is an open gap, missing "reason" is empty'
			);
			self::same(
				[['from' => '2026-10-13 00:21', 'to' => null, 'reason' => 'Still running']],
				Gaps::config([['from' => '2026-10-13 00:21', 'to' => null, 'reason' => 'Still running']]),
				'Config: "to" null is an open gap'
			);
			self::same([], self::logged(), 'Config: nothing logged for valid entries');
			self::same([], Gaps::config(null), 'Config: not set means no gaps, nothing logged');
			self::same([], self::logged(), 'Config: not set logs nothing');
			self::same([], Gaps::config([]), 'Config: empty list');

			// Sorted by start, whatever the order in the configuration.
			$later = ['from' => '2026-10-20 08:00', 'to' => '2026-10-20 09:00', 'reason' => 'Later'];
			self::same(['2026-10-13 00:21', '2026-10-20 08:00'], array_column(Gaps::config([$later, $outage]), 'from'), 'Config: sorted by start');

			// Invalid entries: logged and ignored, the valid ones stay.
			$invalid = [
				['from' => '2026-10-13'],                                      // no time
				['from' => '13.10.2026 00:21'],                                // not the format
				['from' => '2026-02-30 10:00'],                                // no such day
				['from' => '2026-10-13 24:00'],                                // no such hour
				['from' => '2026-10-13 9:05'],                                 // not zero-padded
				['from' => '2026-10-13 00:21 Uhr'],                            // trailing text
				['from' => 1760306460],                                        // not a string
				['reason' => 'No start'],                                      // missing "from"
				['from' => '2026-03-29 02:30', 'to' => '2026-03-29 04:00'],    // 02:30 does not exist on the DST change day
				['from' => '2026-10-13 00:21', 'to' => '2026-10-13 00:20'],    // "to" before "from"
				['from' => '2026-10-13 00:21', 'to' => '2026-10-13 00:21'],    // "to" equal to "from"
				['from' => '2026-10-13 00:21', 'to' => '2026-13-01 00:00'],    // invalid "to"
				['from' => '2026-10-13 00:21', 'to' => ''],                    // empty "to" is not "omitted"
				['from' => '2026-10-13 00:21', 'reason' => ['array']],         // reason not text
				['from' => '2026-10-13 00:21', 'reason' => 5],
				['from' => '2026-10-13 00:21', 'reason' => "bad \xff bytes"], // invalid UTF-8
				'not an array',
				7,
			];
			self::same([$outage], Gaps::config(array_merge([$outage], $invalid)), 'Config: invalid entries ignored, valid ones kept');
			self::same(count($invalid), count(self::logged()), 'Config: every invalid entry logged once');

			// A list that is not a list (a single gap written without the outer list) is ignored as a whole.
			self::same([], Gaps::config($outage), 'Config: a single gap without the list is ignored');
			self::same(1, count(self::logged()), 'Config: non-list logged');
			self::same([], Gaps::config('2026-10-13 00:21'), 'Config: a string is no list');
			self::same([], Gaps::config(['a' => $outage]), 'Config: a keyed array is no list');
			self::same(2, count(self::logged()), 'Config: string and keyed array logged');

			// Reason: plain text, cleaned, at most 200 characters, HTML left for the output to escape.
			self::same(
				'Line one Line two end',
				Gaps::config([['from' => '2026-10-13 00:21', 'reason' => "Line one\nLine two\t\x00 end"]])[0]['reason'],
				'Reason: line breaks, tabs and control characters become single spaces'
			);
			self::same(
				'Power „Cut“ <b>x</b>',
				Gaps::config([['from' => '2026-10-13 00:21', 'reason' => '  Power „Cut“ <b>x</b>  ']])[0]['reason'],
				'Reason: trimmed, quotes and angle brackets kept (escaped on output)'
			);
			self::same(
				200,
				mb_strlen(Gaps::config([['from' => '2026-10-13 00:21', 'reason' => str_repeat('ä', 300)]])[0]['reason']),
				'Reason: at most 200 characters, not bytes'
			);
			self::same(
				str_repeat('x', 199),
				Gaps::config([['from' => '2026-10-13 00:21', 'reason' => str_repeat('x', 199) . ' yz']])[0]['reason'],
				'Reason: no trailing space after the cut'
			);
			self::same(0, count(self::logged()), 'Reason: a long reason is cut silently, not logged');

			// Days touched by a gap. Gaps are [from, to): a gap that ends at midnight does not touch the next day.
			$now = $at('2026-10-14 12:00');
			$gaps = Gaps::config([
				$outage,
				['from' => '2026-10-12 22:00', 'to' => '2026-10-13 00:00', 'reason' => 'Ends at midnight'],
				['from' => '2026-10-20 08:00', 'reason' => 'Starts in the future'],
				['from' => '2026-10-10 08:00', 'reason' => 'Open'],
			]);
			self::same(
				['Open', 'Server outage'],
				array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-13', $now)),
				'Day: the day of the outage is touched, a gap ending at midnight touches the day before only'
			);
			self::same(
				['Open', 'Ends at midnight'],
				array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-12', $now)),
				'Day: the day before a gap ending at midnight is touched'
			);
			self::same(
				['Open'],
				array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-14', $now)),
				'Day: an open gap touches every day up to now'
			);
			self::same([], Gaps::onDay($gaps, '2026-10-20', $now), 'Day: a gap that starts after now touches nothing yet');
			self::same([], Gaps::onDay($gaps, '2026-10-09', $now), 'Day: before every gap');
			self::same([], Gaps::onDay($gaps, 'not a day', $now), 'Day: not a day means nothing');
			// Open gap seen on an earlier day: it ends now, so later days are not touched by it.
			self::same(
				['Open'],
				array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-10', $at('2026-10-10 20:00'))),
				'Day: an open gap is touched on its first day as far as it had run'
			);
			self::same([], Gaps::onDay($gaps, '2026-10-11', $at('2026-10-10 20:00')), 'Day: an open gap seen before the day has nothing to touch');

			// Range overlap, half-open.
			self::same(['Open'], array_map(
				fn ($g) => $g['reason'],
				Gaps::overlapping($gaps, $at('2026-10-13 15:40'), $at('2026-10-14 00:00'), $now)
			), 'Range: a gap ending exactly at the start of the range does not count');
			self::same(['Open', 'Server outage'], array_map(
				fn ($g) => $g['reason'],
				Gaps::overlapping($gaps, $at('2026-10-13 15:39'), $at('2026-10-14 00:00'), $now)
			), 'Range: a minute inside counts');

			// Period text, English and German.
			$outage = Gaps::config([$outage])[0];
			$multi = Gaps::config([['from' => '2026-10-13 22:00', 'to' => '2026-10-14 09:00']])[0];
			$open = Gaps::config([['from' => '2026-10-13 00:21', 'reason' => 'Open']])[0];
			I18n::setLanguage('en');
			self::same('13 Oct 2026 00:21–15:40', Gaps::period($outage, $now), 'Period: same day, time range');
			self::same('13 Oct 2026 22:00–14 Oct 2026 09:00', Gaps::period($multi, $now), 'Period: over midnight, both dates');
			self::same('since 13 Oct 2026 00:21', Gaps::period($open, $now), 'Period: open gap');
			self::same('Measurement gap 13 Oct 2026 00:21–15:40: Server outage', Gaps::line($outage, $now), 'Line: with reason');
			self::same('Measurement gap 13 Oct 2026 00:21–15:40', Gaps::line(Gaps::config([['from' => '2026-10-13 00:21', 'to' => '2026-10-13 15:40']])[0], $now), 'Line: without reason');
			I18n::setLanguage('de');
			self::same('13.10.2026 00:21–15:40', Gaps::period($outage, $now), 'Period (de): same day');
			self::same('seit 13.10.2026 00:21', Gaps::period($open, $now), 'Period (de): open gap');
			self::same('Zählpause 13.10.2026 00:21–15:40: Server outage', Gaps::line($outage, $now), 'Line (de): with reason');
			I18n::setLanguage('en');

			// Dashboard: a marker in both trend charts on each shown day a gap touches,
			// and a note under the charts. Relative days, like the other dashboard tests.
			$today = new DateTimeImmutable('today', $berlin);
			$dayAt = fn (int $offset, int $hour, int $minute = 0) => $today->modify("$offset days")->setTime($hour, $minute);
			$utc = fn (DateTimeImmutable $time) => $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$names = new Names([], [], []);
			$store = new Store("$tmp/dashboard.sqlite");
			for ($offset = -6; $offset <= 0; $offset++) {
				$store->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => "s$offset", 'created_at' => $utc($dayAt($offset, 10))]);
			}
			$yesterday = $today->modify('-1 days')->format('Y-m-d');
			$gapList = Gaps::config([
				['from' => "$yesterday 00:21", 'to' => "$yesterday 15:40", 'reason' => 'Server <b>outage</b>'],
				['from' => '2026-08-01 10:00', 'to' => '2026-08-01 11:00', 'reason' => 'Old outage'],
			]);
			$gapYesterday = $gapList[1]; // sorted by start: the August gap comes first
			$now = new DateTimeImmutable('now', $berlin);
			$html = self::render(Analysis::data($store, $names, 7, ['gaps' => $gapList]));
			self::same(2, substr_count($html, 'class="gap-band"'), 'Dashboard: one band per chart on the gap day, none for the gap outside the range');
			self::same(true, str_contains($html, 'x="450" y="0" width="100" height="160"'), 'Dashboard: the band sits on the day slot (6th of 7 days)');
			self::same(true, str_contains($html, '<title>' . htmlspecialchars(Gaps::line($gapYesterday, $now), ENT_QUOTES, 'UTF-8') . '</title>'), 'Dashboard: band tooltip with period and escaped reason');
			self::same(true, str_contains($html, '<h3>Measurement gaps</h3>') && str_contains($html, '<ul class="gap-notes">'), 'Dashboard: note heading and list');
			self::same(
				true,
				str_contains($html, '<strong>' . htmlspecialchars(Gaps::period($gapYesterday, $now), ENT_QUOTES, 'UTF-8') . '</strong>: Server &lt;b&gt;outage&lt;/b&gt;'),
				'Dashboard: note with period, reason escaped'
			);
			self::same(false, str_contains($html, '<b>outage') || str_contains($html, 'Old outage'), 'Dashboard: no raw reason, no gap outside the shown range');
			self::same(false, str_contains($html, '<script') || (bool)preg_match('/ style="/', $html), 'Dashboard: still no script and no inline style with gaps');
			$plain = self::render(Analysis::data($store, $names, 7, []));
			self::same(false, str_contains($plain, 'gap-band') || str_contains($plain, 'gap-notes') || str_contains($plain, 'Measurement gaps'), 'Dashboard: nothing without kizami.gaps');
			// A gap on a day without any visit still shows: the charts are drawn for it.
			$quiet = self::render(Analysis::data(new Store("$tmp/quiet.sqlite"), $names, 7, ['gaps' => $gapList]));
			self::same(2, substr_count($quiet, 'class="gap-band"'), 'Dashboard: a gap on a day without visits is marked');
			self::same(true, str_contains($quiet, '<ul class="gap-notes">'), 'Dashboard: note also without visits');
			I18n::setLanguage('de');
			$de = self::render(Analysis::data($store, $names, 7, ['gaps' => $gapList]));
			self::same(true, str_contains($de, '<h3>Zählpausen</h3>') && str_contains($de, 'Zählpause ' . Gaps::period($gapYesterday, $now)), 'Dashboard: German heading and tooltip');
			I18n::setLanguage('en');

			// Report: flags for the period and its comparison (one period earlier,
			// seven days back), a flag per day of a week, the gaps list, text lines.
			$reportStore = new Store("$tmp/report.sqlite");
			foreach ([-8, -1] as $offset) {
				$reportStore->write(['type' => 'page', 'action' => 'view', 'path' => '/', 'session' => "r$offset", 'created_at' => $utc($dayAt($offset, 9))]);
			}
			$gapA = ['from' => $dayAt(-1, 0, 21)->format('Y-m-d H:i'), 'to' => $dayAt(-1, 15, 40)->format('Y-m-d H:i'), 'reason' => 'Server outage'];
			$gapB = ['from' => $dayAt(-8, 10)->format('Y-m-d H:i'), 'to' => $dayAt(-8, 12)->format('Y-m-d H:i'), 'reason' => 'Comparison outage'];
			$gapC = ['from' => $dayAt(-20, 10)->format('Y-m-d H:i'), 'to' => $dayAt(-20, 11)->format('Y-m-d H:i'), 'reason' => 'Unrelated'];
			$reportGaps = Gaps::config([$gapA, $gapB, $gapC]);
			$reportNow = new DateTimeImmutable('now', $berlin);
			$day = Report::data($reportStore, $names, 'day', $today, ['brand' => 'Test', 'gaps' => $reportGaps, 'now' => $reportNow]);
			self::same([true, true], [$day['gap'], $day['gapPrevious']], 'Report: the day and its comparison are flagged');
			self::same([
				['from' => $gapB['from'], 'to' => $gapB['to'], 'reason' => 'Comparison outage', 'current' => false, 'previous' => true],
				['from' => $gapA['from'], 'to' => $gapA['to'], 'reason' => 'Server outage', 'current' => true, 'previous' => false],
			], $day['gaps'], 'Report: gaps touching the period or its comparison, unrelated gaps left out');
			self::same(
				[Gaps::line(Gaps::config([$gapA])[0], $reportNow), I18n::t('report.gapPrevious')],
				array_slice(explode("\n", $day['text']), 1, 2),
				'Report: text names the gap of the day, then the flagged comparison'
			);
			$quiet = Report::data($reportStore, $names, 'day', $today->modify('-2 days'), ['brand' => 'Test', 'gaps' => $reportGaps, 'now' => $reportNow]);
			self::same([false, false, []], [$quiet['gap'], $quiet['gapPrevious'], $quiet['gaps']], 'Report: a day without gap and without a gap in its comparison is not flagged');
			self::same(false, str_contains($quiet['text'], 'Measurement gap'), 'Report: no gap line for a clean day');
			self::same(false, str_contains(Report::data($reportStore, $names, 'day', $today, ['brand' => 'Test'])['text'], 'Measurement gap'), 'Report: no gaps configured, no line');
			self::same(
				['gaps' => [], 'gap' => false],
				array_intersect_key(Report::data($reportStore, $names, 'day', $today, ['brand' => 'Test']), ['gap' => 1, 'gaps' => 1]),
				'Report: fields present without gaps'
			);
			// Week: the gap on a Wednesday of the last full week flags that day only.
			$thisMonday = $today->modify('-' . ((int)$today->format('N') - 1) . ' days');
			$wednesday = $thisMonday->modify('-5 days');
			$weekGaps = Gaps::config([['from' => $wednesday->format('Y-m-d') . ' 06:00', 'to' => $wednesday->format('Y-m-d') . ' 08:00', 'reason' => 'Wednesday']]);
			$week = Report::data(new Store("$tmp/week.sqlite"), $names, 'week', $today, ['brand' => 'Test', 'gaps' => $weekGaps, 'now' => $reportNow]);
			self::same([true, false], [$week['gap'], $week['gapPrevious']], 'Week: the week is flagged');
			self::same(
				[$wednesday->format('Y-m-d')],
				array_values(array_map(fn ($d) => $d['day'], array_filter($week['perDay'], fn ($d) => $d['gap']))),
				'Week: exactly that day carries the flag'
			);
			self::same(Gaps::line($weekGaps[0], $reportNow), explode("\n", $week['text'])[1], 'Week: text line for the gap');
			self::same(true, array_key_exists('gap', $week) && array_key_exists('gapPrevious', $week), 'Week: fields present');

		} catch (Throwable $e) {
			throw $e;
		} finally {
			foreach (glob("$tmp/*") ?: [] as $f) {
				unlink($f);
			}
			rmdir($tmp);
		}
		$this->assertFalse($failed ?? false, 'no failure');
		restore_error_handler();
	}
}
