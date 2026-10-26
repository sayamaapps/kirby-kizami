<?php

/**
 * Translations: every language has the same keys and placeholders as
 * English, and numbers and dates follow the language.
 *   php tests/I18nTest.php
 */
require_once __DIR__ . '/../src/I18n.php';
use Kizami\I18n;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class I18nTest extends TestCase
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
		$n = 0;
		$placeholders = function (string $text): array {
			preg_match_all('/\{(\w+)\}/', $text, $m);
			$names = array_unique($m[1]);
			sort($names);
			return $names;
		};
		try {
			$en = I18n::table('en');
			foreach (I18n::LANGUAGES as $language) {
				$table = I18n::table($language);
				self::expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
				self::expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
				foreach ($en as $key => $text) {
					self::expect(true, is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
					if (str_starts_with($key, 'date.')) {
						// Date patterns pick their own parts, but only from what I18n fills in.
						self::expect([], array_values(array_diff($placeholders($table[$key]), ['day', 'dd', 'month', 'mm', 'mon', 'year'])), "$language.$key: known date parts only");
						continue;
					}
					self::expect($placeholders($text), $placeholders($table[$key]), "$language.$key: same placeholders as English");
				}
			}
			self::expect([], I18n::table('fr'), 'unknown language: no table');

			I18n::setLanguage('fr');
			self::expect('en', I18n::language(), 'unknown language falls back to English');
			self::expect('Visits', I18n::t('tile.visits'), 'English default');
			self::expect('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
			self::expect('Campaign “qr”', I18n::t('source.campaign', ['name' => 'qr']), 'placeholder replaced');
			self::expect('1,234.5', I18n::number(1234.5, 1), 'English number format');
			$day = new DateTimeImmutable('2026-10-06');
			self::expect(['Tue', '6 Oct', '6 Oct 2026', 'Sep 26'], [I18n::weekday(2), I18n::shortDate($day), I18n::date($day), I18n::monthYear('2026-09')], 'English dates');

			I18n::setLanguage('de');
			self::expect('de', I18n::language(), 'German selected');
			self::expect('Besuche', I18n::t('tile.visits'), 'German string');
			self::expect('1.234,5', I18n::number(1234.5, 1), 'German number format');
			self::expect(['Di', '06.10.', '6.10.2026', 'Sep. 26', 'März 27'], [I18n::weekday(2), I18n::shortDate($day), I18n::date($day), I18n::monthYear('2026-09'), I18n::monthYear('2027-03')], 'German dates');
			I18n::setLanguage('en');

		} catch (Throwable $e) {
			throw $e;
		}
	}
}
