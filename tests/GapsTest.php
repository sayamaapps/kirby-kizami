<?php
/**
 * Measurement gaps (kizami.gaps, since 1.5.0) without Kirby: the option
 * validation, the overlap with days and ranges, the period text.
 *   php tests/GapsTest.php
 */
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Gaps.php';

use Kizami\Gaps;
use Kizami\I18n;

// Every warning or notice is a failure: a bad entry is ignored by design,
// not by accident of a function returning null.
set_error_handler(function (int $level, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $level, $file, $line);
});

$tmp = sys_get_temp_dir() . '/kizami-gaps-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$log = "$tmp/error.log";
ini_set('error_log', $log);
$n = 0;
function same($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}
/** Log lines written since the last call; resets the log. */
function logged(): array
{
    global $log;
    $lines = is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    if (is_file($log)) { unlink($log); }
    return $lines;
}
$berlin = new DateTimeZone('Europe/Berlin');
$at = fn (string $time) => new DateTimeImmutable($time, $berlin);

try {
    // Valid entries: from and to, reason optional, open gap without 'to'.
    $outage = ['from' => '2026-10-13 00:21', 'to' => '2026-10-13 15:40', 'reason' => 'Server outage'];
    same([$outage], Gaps::config([$outage]), 'Config: a valid gap is kept as it is');
    same(
        [['from' => '2026-10-13 00:21', 'to' => null, 'reason' => '']],
        Gaps::config([['from' => '2026-10-13 00:21']]),
        'Config: missing "to" is an open gap, missing "reason" is empty'
    );
    same(
        [['from' => '2026-10-13 00:21', 'to' => null, 'reason' => 'Still running']],
        Gaps::config([['from' => '2026-10-13 00:21', 'to' => null, 'reason' => 'Still running']]),
        'Config: "to" null is an open gap'
    );
    same([], logged(), 'Config: nothing logged for valid entries');
    same([], Gaps::config(null), 'Config: not set means no gaps, nothing logged');
    same([], logged(), 'Config: not set logs nothing');
    same([], Gaps::config([]), 'Config: empty list');

    // Sorted by start, whatever the order in the configuration.
    $later = ['from' => '2026-10-20 08:00', 'to' => '2026-10-20 09:00', 'reason' => 'Later'];
    same(['2026-10-13 00:21', '2026-10-20 08:00'], array_column(Gaps::config([$later, $outage]), 'from'), 'Config: sorted by start');

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
    same([$outage], Gaps::config(array_merge([$outage], $invalid)), 'Config: invalid entries ignored, valid ones kept');
    same(count($invalid), count(logged()), 'Config: every invalid entry logged once');

    // A list that is not a list (a single gap written without the outer list) is ignored as a whole.
    same([], Gaps::config($outage), 'Config: a single gap without the list is ignored');
    same(1, count(logged()), 'Config: non-list logged');
    same([], Gaps::config('2026-10-13 00:21'), 'Config: a string is no list');
    same([], Gaps::config(['a' => $outage]), 'Config: a keyed array is no list');
    same(2, count(logged()), 'Config: string and keyed array logged');

    // Reason: plain text, cleaned, at most 200 characters, HTML left for the output to escape.
    same('Line one Line two end', Gaps::config([['from' => '2026-10-13 00:21', 'reason' => "Line one\nLine two\t\x00 end"]])[0]['reason'],
        'Reason: line breaks, tabs and control characters become single spaces');
    same('Power „Cut“ <b>x</b>', Gaps::config([['from' => '2026-10-13 00:21', 'reason' => '  Power „Cut“ <b>x</b>  ']])[0]['reason'],
        'Reason: trimmed, quotes and angle brackets kept (escaped on output)');
    same(200, mb_strlen(Gaps::config([['from' => '2026-10-13 00:21', 'reason' => str_repeat('ä', 300)]])[0]['reason']),
        'Reason: at most 200 characters, not bytes');
    same(str_repeat('x', 199), Gaps::config([['from' => '2026-10-13 00:21', 'reason' => str_repeat('x', 199) . ' yz']])[0]['reason'],
        'Reason: no trailing space after the cut');
    same(0, count(logged()), 'Reason: a long reason is cut silently, not logged');

    // Days touched by a gap. Gaps are [from, to): a gap that ends at midnight does not touch the next day.
    $now = $at('2026-10-14 12:00');
    $gaps = Gaps::config([
        $outage,
        ['from' => '2026-10-12 22:00', 'to' => '2026-10-13 00:00', 'reason' => 'Ends at midnight'],
        ['from' => '2026-10-20 08:00', 'reason' => 'Starts in the future'],
        ['from' => '2026-10-10 08:00', 'reason' => 'Open'],
    ]);
    same(['Open', 'Server outage'], array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-13', $now)),
        'Day: the day of the outage is touched, a gap ending at midnight touches the day before only');
    same(['Open', 'Ends at midnight'], array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-12', $now)),
        'Day: the day before a gap ending at midnight is touched');
    same(['Open'], array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-14', $now)),
        'Day: an open gap touches every day up to now');
    same([], Gaps::onDay($gaps, '2026-10-20', $now), 'Day: a gap that starts after now touches nothing yet');
    same([], Gaps::onDay($gaps, '2026-10-09', $now), 'Day: before every gap');
    same([], Gaps::onDay($gaps, 'not a day', $now), 'Day: not a day means nothing');
    // Open gap seen on an earlier day: it ends now, so later days are not touched by it.
    same(['Open'], array_map(fn ($g) => $g['reason'], Gaps::onDay($gaps, '2026-10-10', $at('2026-10-10 20:00'))),
        'Day: an open gap is touched on its first day as far as it had run');
    same([], Gaps::onDay($gaps, '2026-10-11', $at('2026-10-10 20:00')), 'Day: an open gap seen before the day has nothing to touch');

    // Range overlap, half-open.
    same(['Open'], array_map(fn ($g) => $g['reason'],
        Gaps::overlapping($gaps, $at('2026-10-13 15:40'), $at('2026-10-14 00:00'), $now)), 'Range: a gap ending exactly at the start of the range does not count');
    same(['Open', 'Server outage'], array_map(fn ($g) => $g['reason'],
        Gaps::overlapping($gaps, $at('2026-10-13 15:39'), $at('2026-10-14 00:00'), $now)), 'Range: a minute inside counts');

    // Period text, English and German.
    $outage = Gaps::config([$outage])[0];
    $multi = Gaps::config([['from' => '2026-10-13 22:00', 'to' => '2026-10-14 09:00']])[0];
    $open = Gaps::config([['from' => '2026-10-13 00:21', 'reason' => 'Open']])[0];
    I18n::setLanguage('en');
    same('13 Oct 2026 00:21–15:40', Gaps::period($outage, $now), 'Period: same day, time range');
    same('13 Oct 2026 22:00–14 Oct 2026 09:00', Gaps::period($multi, $now), 'Period: over midnight, both dates');
    same('since 13 Oct 2026 00:21', Gaps::period($open, $now), 'Period: open gap');
    same('Measurement gap 13 Oct 2026 00:21–15:40: Server outage', Gaps::line($outage, $now), 'Line: with reason');
    same('Measurement gap 13 Oct 2026 00:21–15:40', Gaps::line(Gaps::config([['from' => '2026-10-13 00:21', 'to' => '2026-10-13 15:40']])[0], $now), 'Line: without reason');
    I18n::setLanguage('de');
    same('13.10.2026 00:21–15:40', Gaps::period($outage, $now), 'Period (de): same day');
    same('seit 13.10.2026 00:21', Gaps::period($open, $now), 'Period (de): open gap');
    same('Zählpause 13.10.2026 00:21–15:40: Server outage', Gaps::line($outage, $now), 'Line (de): with reason');
    I18n::setLanguage('en');

    echo "Gaps: all $n checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Gaps: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    $failed = true;
} finally {
    foreach (glob("$tmp/*") ?: [] as $f) { unlink($f); }
    rmdir($tmp);
}
exit(isset($failed) ? 1 : 0);
