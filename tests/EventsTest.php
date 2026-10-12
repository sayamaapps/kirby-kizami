<?php
/**
 * Reported actions (kizami.events, since 1.4.0) without Kirby: the allow
 * list, the cleaning of the detail, the size cap, the table of its own with
 * retention, the numbers, the dashboard section and the report field.
 *   php tests/EventsTest.php
 */
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Capture.php';
require_once __DIR__ . '/../src/Events.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/Names.php';
require_once __DIR__ . '/../src/Comparison.php';
require_once __DIR__ . '/../src/Chart.php';
require_once __DIR__ . '/../src/Analysis.php';
require_once __DIR__ . '/../src/Report.php';

use Kizami\Analysis;
use Kizami\Capture;
use Kizami\Events;
use Kizami\I18n;
use Kizami\Names;
use Kizami\Report;
use Kizami\Store;

// Every warning, notice or deprecation is a failure: the cleaning must not
// "work" only because a function returned null on bad input.
set_error_handler(function (int $level, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $level, $file, $line);
});

$tmp = sys_get_temp_dir() . '/kizami-events-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$n = 0;
function same($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}
function render(array $data): string
{
    ob_start();
    include __DIR__ . '/../views/dashboard.php';
    return ob_get_clean();
}

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
    same(['download', 'video_play', 'social-click', 'resume', str_repeat('b', 32), 'labels'], array_keys($config), 'Config: only valid keys with an array entry');
    same([true, false, false, false], [$config['download']['detail'], $config['video_play']['detail'], $config['social-click']['detail'], $config['resume']['detail']], "Config: detail only with exactly true ('yes' is not true)");
    same([], Events::config(null), 'Config: not set means empty');
    same([], Events::config('download'), 'Config: a string is no list');
    same('Video gestartet', Events::label($config, 'video_play', 'de'), 'Label in the dashboard language');
    same('Video played', Events::label($config, 'video_play', 'fr'), 'Label falls back to English');
    same('Social', Events::label($config, 'social-click', 'de'), 'A string label is the English label');
    same('Étiquette', Events::label($config, 'labels', 'de'), 'Empty and non-string labels are skipped, then the first one');
    same('resume', Events::label($config, 'resume', 'de'), 'Without a label: the key');
    foreach (['download', 'a', '0-9_x', str_repeat('z', 32)] as $key) { same(true, Events::isKey($key), "Key accepted: $key"); }
    foreach (['', 'Download', 'down load', 'a.b', 'ä', str_repeat('z', 33), "download\n", ['download'], null, 5] as $key) {
        same(false, Events::isKey($key), 'Key rejected: ' . json_encode($key));
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
        same($expected, Capture::cleanDetail($raw), 'cleanDetail: ' . $what);
    }
    foreach ([null, 5, ['a'], true, "\xff\xfeabc", "abc\xc3"] as $raw) {
        same('', Capture::cleanDetail($raw), 'cleanDetail: rejected ' . json_encode(is_string($raw) ? bin2hex($raw) : $raw));
    }
    same(100, mb_strlen(Capture::cleanDetail(str_repeat('ä', 150))), 'cleanDetail: at most 100 characters, not bytes');
    same(str_repeat('ä', 100), Capture::cleanDetail(str_repeat('ä', 150)), 'cleanDetail: cut on a character boundary');
    same(str_repeat('x', 99), Capture::cleanDetail(str_repeat('x', 99) . ' yz'), 'cleanDetail: no trailing space after cutting');
    same(false, str_contains(Capture::cleanDetail(str_repeat('a', 90) . ' https://x.de/' . str_repeat('p', 20) . '?SECRET'), 'SECRET'), 'cleanDetail: query removed before cutting');

    // Size cap of /k/event.
    same(false, Capture::requestTooLarge(['CONTENT_LENGTH' => '120'], 2048), 'Size: normal beacon');
    same(false, Capture::requestTooLarge(['CONTENT_LENGTH' => '2048'], 2048), 'Size: exactly the limit');
    same(true, Capture::requestTooLarge(['CONTENT_LENGTH' => '2049'], 2048), 'Size: one byte over');
    same(true, Capture::requestTooLarge(['CONTENT_LENGTH' => '-1'], 2048), 'Size: no number');
    same(true, Capture::requestTooLarge(['CONTENT_LENGTH' => '1e9'], 2048), 'Size: no plain number');
    same(false, Capture::requestTooLarge([], 2048), 'Size: no body');
    same(true, Capture::requestTooLarge(['QUERY_STRING' => str_repeat('a', 2049)], 2048), 'Size: long query string');

    // Table of its own; numbers per key; visits per Berlin day and identifier.
    $store = new Store("$tmp/k.sqlite");
    $db = new SQLite3("$tmp/k.sqlite");
    $berlin = new DateTimeZone('Europe/Berlin');
    $today = new DateTimeImmutable('today', $berlin);
    $store->setNow($today->setTime(12, 0));
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
    same(0, $db->querySingle("SELECT COUNT(*) FROM kizami_events WHERE type NOT IN ('page')"), 'Actions are not written to kizami_events');
    same(9, $db->querySingle('SELECT COUNT(*) FROM kizami_custom_events'), 'Actions written to kizami_custom_events');
    same(['created_at' => $at(0), 'event' => 'download', 'detail' => 'a.pdf', 'path' => '/', 'session' => 'a'],
        $db->query('SELECT created_at, event, detail, path, session FROM kizami_custom_events ORDER BY id LIMIT 1')->fetchArray(SQLITE3_ASSOC),
        'Row: key, detail, page, day identifier — no device, no other fields');
    same([
        ['event' => 'download', 'count' => 6, 'visits' => 3],
        ['event' => 'gone', 'count' => 1, 'visits' => 1],
        ['event' => 'video_play', 'count' => 1, 'visits' => 1],
    ], $store->customEvents(7), 'Total and visits per action (visits per Berlin day, empty identifier not a visit)');
    same([['event' => 'video_play', 'count' => 1, 'visits' => 1]], $store->customEvents(7, 1), 'Previous period');
    same([['event' => 'download', 'count' => 5, 'visits' => 2], ['event' => 'gone', 'count' => 1, 'visits' => 1], ['event' => 'video_play', 'count' => 1, 'visits' => 1]],
        $store->customEvents(1), 'One day');
    same(['details' => [
        ['detail' => 'a.pdf', 'count' => 4, 'visits' => 3],
        ['detail' => 'b.pdf', 'count' => 1, 'visits' => 1],
        ['detail' => 'c.pdf', 'count' => 1, 'visits' => 0],
    ], 'omitted' => 0], $store->customEventDetails('download', 7, 10), 'Details: by count, then name');
    same(['details' => [['detail' => 'a.pdf', 'count' => 4, 'visits' => 3]], 'omitted' => 2], $store->customEventDetails('download', 7, 1), 'Details: capped, the rest counted');
    same(['details' => [], 'omitted' => 0], $store->customEventDetails('video_play', 7, 10), 'Details: empty details are no details');
    same($before, [$store->visits(7), $store->pageViews(7), $store->clicks(7), $store->devices(7), $store->longTerm()], 'Actions change neither visits, page views, clicks, devices nor the long term');

    // Retention: five years, like the single events; not rolled up.
    $old = (new DateTimeImmutable('today', $berlin))->modify('-5 years -1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $recent = (new DateTimeImmutable('today', $berlin))->modify('-5 years +1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $retention = new Store("$tmp/retention.sqlite");
    $retention->write(['type' => 'page', 'action' => 'view', 'session' => 'o', 'created_at' => $old]);
    $retention->write(['type' => 'event', 'event' => 'download', 'detail' => 'old.pdf', 'session' => 'o', 'created_at' => $old]);
    $retention->write(['type' => 'event', 'event' => 'download', 'detail' => 'recent.pdf', 'session' => 'o', 'created_at' => $recent]);
    same(1, $retention->compact(), 'Retention: compact() still returns the rolled-up single events');
    $rdb = new SQLite3("$tmp/retention.sqlite");
    same(['recent.pdf'], array_column(iterator_to_array((function () use ($rdb) { $r = $rdb->query('SELECT detail FROM kizami_custom_events'); while ($row = $r->fetchArray(SQLITE3_ASSOC)) { yield $row; } })(), false), 'detail'),
        'Retention: actions older than five years deleted, younger ones kept');
    same(['views' => 1, 'clicks' => 0, 'devices' => 1], $rdb->query('SELECT views, clicks, devices FROM kizami_days')->fetchArray(SQLITE3_ASSOC), 'Retention: actions are not part of the daily totals');
    $rdb->close();
    // The daily compaction on write deletes them as well.
    $daily = new Store("$tmp/daily.sqlite");
    $daily->write(['type' => 'event', 'event' => 'download', 'session' => 'o', 'created_at' => $old]);
    $ddb = new SQLite3("$tmp/daily.sqlite");
    $ddb->exec('DELETE FROM kizami_maintenance');
    $daily->write(['type' => 'page', 'action' => 'view', 'session' => 'n']);
    same(0, $ddb->querySingle('SELECT COUNT(*) FROM kizami_custom_events'), 'Retention: the daily compaction on the first write of a day');
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
    same([1, 1], [$ldb->querySingle("SELECT COUNT(*) FROM kizami_events WHERE path = '/kept'"), $ldb->querySingle('SELECT COUNT(*) FROM kizami_custom_events')], 'Upgrade from 1.3.0: table created, events kept');
    $ldb->close();

    $db->close();
    echo "Actions: all $n checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Actions: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    $failed = true;
} finally {
    unset($store, $report, $retention, $daily, $upgraded);
    foreach (glob("$tmp/*") as $f) { unlink($f); }
    rmdir($tmp);
}
exit(isset($failed) ? 1 : 0);
