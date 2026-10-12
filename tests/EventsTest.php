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
