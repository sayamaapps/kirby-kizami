<?php

/** Charts without Kirby: valid XML, escaping, edge cases.
 *   php tests/ChartTest.php
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
// K5: hit areas must not overlap — the boundary to the neighbour lies on the
// midpoint, so the first and last area are half as wide.
// C-b: forced decimal for uniform comparison values.
// Time on site, readable.
// German: separators, durations and chart strings follow the language.
// Names from stored rows may hold invalid UTF-8 (before 1.6.1): shown with
// U+FFFD, not dropped — htmlspecialchars() without ENT_SUBSTITUTE returns ''.

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Chart;
use Sayamaapps\Kizami\I18n;

#[CoversClass(\Sayamaapps\Kizami\Chart::class)]
#[RunTestsInSeparateProcesses]
final class ChartTest extends TestCase
{
	public function testSuite(): void
	{
		$shades = Chart::shades('#2f4f2a');
		Assert::assertTrue(count($shades) === 5, '5 shades');
		Assert::assertTrue(strtolower($shades[4]) === '#2f4f2a', 'Last shade is the base');
		Assert::assertTrue(hexdec(substr($shades[0], 1, 2)) > hexdec('2f'), 'First shade is lighter than the base');
		Assert::assertTrue(count(array_filter($shades, fn ($s) => preg_match('/^#[0-9a-f]{6}$/i', $s))) === 5, 'All shades are hex');
		$luminance = static function (string $hex): float {
			$rgb = array_map(static function ($v) {
				$v /= 255;
				return $v <= .04045 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4;
			}, sscanf($hex, '#%02x%02x%02x'));
			return .2126 * $rgb[0] + .7152 * $rgb[1] + .0722 * $rgb[2];
		};
		for ($i = 1; $i < count($shades); $i++) {
			Assert::assertTrue($luminance($shades[$i]) < $luminance($shades[$i - 1]), 'Shades get steadily darker');
		}
		foreach (['#37352c', '#6b675a', '#2f4f2a', '#a8412f', '#3a6db0'] as $color) {
			Assert::assertTrue(($luminance('#f8f4e9') + .05) / ($luminance($color) + .05) >= 4.5, 'Text contrast ' . $color);
		}
		$sp = Chart::sparkline([1, 5, 3, 8, 2]);
		Assert::assertTrue(self::wellFormed($sp), 'Sparkline well-formed');
		Assert::assertTrue(str_contains($sp, '<polyline') && str_contains($sp, '<circle'), 'Sparkline has polyline and end point');
		Assert::assertTrue(self::wellFormed(Chart::sparkline([])) && str_contains(Chart::sparkline([]), 'No data yet'), 'Empty sparkline still renders');
		Assert::assertTrue(self::wellFormed(Chart::sparkline([7])), 'Sparkline with one value');
		Assert::assertTrue(self::wellFormed(Chart::sparkline([0, 0, 0])), 'Sparkline of only zeros without division by 0');
		$b = Chart::bars([
			['name' => 'Call <script>alert(1)</script>', 'value' => 61],
			['name' => 'Route', 'value' => 26, 'share' => '30 %'],
		]);
		Assert::assertTrue(self::wellFormed($b), 'Bars well-formed');
		Assert::assertTrue(!str_contains($b, '<script>') && str_contains($b, '&lt;script&gt;'), 'Bars escape names');
		Assert::assertTrue(str_contains($b, 'width="100"'), 'Bars: largest value is 100 wide');
		Assert::assertTrue(str_contains($b, '30 %'), 'Bars: share appears');
		Assert::assertTrue(str_contains($b, '<title>Route: 26</title>'), 'Bars: title contains value');
		$many = array_map(fn ($i) => ['name' => "Q$i", 'value' => 20 - $i], range(1, 12));
		$b12 = Chart::bars($many, 8);
		Assert::assertTrue(substr_count($b12, '<li') === 9, 'Bars: 9 rows (8 + Other)');
		Assert::assertTrue(str_contains($b12, 'Other') && str_contains($b12, '<title>Other: 38</title>'), 'Bars: Other sums the rows from 9 on (11+10+9+8)');
		Assert::assertTrue(str_contains(Chart::bars([]), 'No data yet'), 'Bars empty');
		$s = Chart::columns([['name' => '2026-08', 'value' => 10], ['name' => '2026-09', 'value' => 40]]);
		Assert::assertTrue(self::wellFormed($s), 'Columns well-formed');
		Assert::assertTrue(substr_count($s, '<rect') === 2, 'Columns: two rectangles');
		Assert::assertTrue(str_contains($s, 'month-axis') && str_contains($s, '<span>40</span>'), 'Columns: month labels and maximum value');
		Assert::assertTrue(str_contains(Chart::columns([]), 'No data yet'), 'Columns empty');
		$f = Chart::area([['name' => '2026-09-01', 'value' => 3], ['name' => '2026-09-02', 'value' => 0], ['name' => '2026-09-03', 'value' => 9]]);
		Assert::assertTrue(self::wellFormed($f), 'Area well-formed');
		Assert::assertTrue(str_contains($f, '<path') && str_contains($f, '<polyline') && substr_count($f, 'area-hit') === 3, 'Area: path, line, 3 hit areas');
		Assert::assertTrue(str_contains($f, '<title>2026-09-03: 9</title>'), 'Area: title with date and value');
		Assert::assertTrue(str_contains($f, '<rect class="area-hit" x="0" y="0" width="150"'), 'Area: first hit rect at x=0 with width 150');
		Assert::assertTrue(str_contains($f, '<rect class="area-hit" x="450" y="0" width="150"'), 'Area: last hit rect ends at 600');
		Assert::assertTrue(self::wellFormed(Chart::area([['name' => 'x', 'value' => 1]])), 'Area with one point');
		Assert::assertTrue(str_contains(Chart::area([]), 'No data yet'), 'Area empty');
		$matrix = array_fill(0, 7, array_fill(0, 17, 0));
		$matrix[2][5] = 40;
		$matrix[0][0] = 1;
		$r = Chart::grid($matrix, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], array_map('strval', range(6, 22)));
		Assert::assertTrue(self::wellFormed($r), 'Grid well-formed');
		Assert::assertTrue(str_contains($r, 'class="shade-5"'), 'Grid: maximum is shade-5');
		Assert::assertTrue(substr_count($r, 'class="shade-0"') === 7 * 17 - 2, 'Grid: zero is shade-0');
		Assert::assertTrue(str_contains($r, 'class="shade-1"'), 'Grid: small value is shade-1');
		Assert::assertTrue(substr_count($r, '<tr>') === 8, 'Grid: 7 rows');
		Assert::assertTrue(str_contains($r, 'title="Wed 11:00: 40"') && str_contains($r, '<span class="sr-only">40</span>'), 'Grid: cell has tooltip and hidden number');
		Assert::assertTrue(str_contains(Chart::area([['name' => 'x', 'value' => 1]], 'Visits per day'), 'aria-label="Visits per day"'), 'Area has an accessible name');
		Assert::assertTrue(str_contains(Chart::columns([['name' => 'x', 'value' => 1]], 'Months'), 'aria-label="Months"'), 'Columns have an accessible name');
		Assert::assertTrue(self::wellFormed(Chart::grid(array_fill(0, 7, array_fill(0, 17, 0)), ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], array_map('strval', range(6, 22)))), 'Grid of only zeros');
		Assert::assertTrue(Chart::number(1117) === '1,117', 'Number with thousands separator');
		Assert::assertTrue(Chart::number(12.4) === '12.4', 'Number with decimal point');
		Assert::assertTrue(Chart::number(15, 1) === '15.0', 'Number with fixed decimal on a whole number');
		Assert::assertTrue(Chart::number(13.94, 1) === '13.9', 'Number with fixed decimal rounds');
		Assert::assertTrue(Chart::duration(45) === '45 s', 'Duration under a minute');
		Assert::assertTrue(Chart::duration(0) === '0 s', 'Duration at 0');
		Assert::assertTrue(Chart::duration(80) === '1 min 20 s', 'Duration from one minute with remainder');
		Assert::assertTrue(Chart::duration(120) === '2 min 0 s', 'Duration exactly on the minute');
		Assert::assertTrue(Chart::duration(3600) === '60 min 0 s', 'Duration over an hour stays in minutes');
		Assert::assertTrue(Chart::duration(-5) === '0 s', 'Negative seconds are not shown negative');
		foreach (['sparkline' => [0, 0], 'bars' => [['name' => 'x', 'value' => 0]], 'columns' => [['name' => 'x', 'value' => 0]], 'area' => [['name' => 'x', 'value' => 0]]] as $form => $data) {
			Assert::assertTrue(str_contains(Chart::$form($data), 'No data yet'), "$form: zero series is a placeholder");
		}
		foreach (['bars', 'columns', 'area'] as $form) {
			$graphic = Chart::$form([['name' => '<script>"&', 'value' => 1], ['name' => 'Outlier', 'value' => 100000]]);
			Assert::assertTrue(self::wellFormed($graphic) && !str_contains($graphic, '<script>') && str_contains($graphic, '&lt;script&gt;'), "$form: outliers and special characters");
		}
		Assert::assertTrue(str_contains(Chart::grid([], [], []), 'No data yet'), 'Empty grid as a placeholder');
		I18n::setLanguage('de');
		Assert::assertTrue(Chart::number(1117) === '1.117', 'de: number with thousands dot');
		Assert::assertTrue(Chart::number(12.4) === '12,4', 'de: number with decimal comma');
		Assert::assertTrue(Chart::number(15, 1) === '15,0', 'de: fixed decimal on a whole number');
		Assert::assertTrue(Chart::number(13.94, 1) === '13,9', 'de: fixed decimal rounds');
		Assert::assertTrue(Chart::duration(80) === '1 Min 20 s', 'de: duration with remainder');
		Assert::assertTrue(Chart::duration(3600) === '60 Min 0 s', 'de: duration over an hour');
		Assert::assertTrue(str_contains(Chart::bars([]), 'Noch keine Daten'), 'de: empty placeholder');
		Assert::assertTrue(str_contains(Chart::bars($many, 8), '<title>Sonstige: 38</title>'), 'de: Other is "Sonstige"');
		Assert::assertTrue(str_contains(Chart::grid($matrix, ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'], array_map('strval', range(6, 22))), 'title="Mi 11 Uhr: 40"'), 'de: grid tooltip');
		I18n::setLanguage('en');
		$invalidBars = Chart::bars([['name' => "foo\xFF.example", 'value' => 3]]);
		Assert::assertTrue(str_contains($invalidBars, "<span class=\"bar-name\">foo\u{FFFD}.example</span>") && str_contains($invalidBars, "<title>foo\u{FFFD}.example: 3</title>"), 'Bars: invalid UTF-8 in a name becomes U+FFFD, not empty');
		Assert::assertTrue(preg_match('//u', $invalidBars) === 1, 'Bars: output with an invalid name stays valid UTF-8');
		Assert::assertTrue(str_contains(Chart::columns([['name' => "a\xFFb", 'value' => 2]]), "<title>a\u{FFFD}b: 2</title>"), 'Column titles: invalid UTF-8 becomes U+FFFD');
	}

	private static function wellFormed(string $html): bool
	{
		$before = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$ok = $dom->loadXML('<wrap>' . $html . '</wrap>');
		libxml_clear_errors();
		libxml_use_internal_errors($before);
		return $ok !== false;
	}
}
