<?php

/**
 * Retention of single events and reported actions (kizami.retentionMonths,
 * default 13 months): roll-up and deletion at the cutoff, the option's
 * validation with real Kirby, and the report's comparison limit.
 *   php tests/RetentionTest.php
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
// Time in UTC, the given number of months before today (Berlin).

use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Options;
use Sayamaapps\Kizami\Report;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Options::class)]
#[CoversClass(\Sayamaapps\Kizami\Report::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class RetentionTest extends TestCase
{
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
		global $n;
		$base = dirname(__DIR__);
		require $base . '/vendor/autoload.php';
		require_once $base . '/src/I18n.php';
		require_once $base . '/src/Store.php';
		require_once $base . '/src/Options.php';
		require_once $base . '/src/Report.php';
		$logFile = sys_get_temp_dir() . '/kizami-retention-log-' . bin2hex(random_bytes(6)) . '.txt';
		ini_set('error_log', $logFile);
		$tmp = sys_get_temp_dir() . '/kizami-retention-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		$n = 0;
		$berlin = new DateTimeZone('Europe/Berlin');
		$utc = new DateTimeZone('UTC');
		$ago = fn (string $modifier) => (new DateTimeImmutable('today', $berlin))->modify($modifier)->setTimezone($utc)->format('Y-m-d H:i:s');
		try {
			// Default: 13 months. A 14-month-old page and action are rolled up /
			// deleted, a 12-month-old pair stays.
			$store = new Store("$tmp/default.sqlite");
			$store->write(['type' => 'page', 'action' => 'view', 'path' => '/old', 'session' => 'o', 'created_at' => $ago('-14 months -1 day')]);
			$store->write(['type' => 'event', 'event' => 'download', 'detail' => 'old.pdf', 'session' => 'o', 'created_at' => $ago('-14 months -1 day')]);
			$store->write(['type' => 'page', 'action' => 'view', 'path' => '/young', 'session' => 'y', 'created_at' => $ago('-12 months')]);
			$store->write(['type' => 'event', 'event' => 'download', 'detail' => 'young.pdf', 'session' => 'y', 'created_at' => $ago('-12 months')]);
			self::same(1, $store->compact(), 'Default: one 14-month-old single event is rolled up');
			$db = new SQLite3("$tmp/default.sqlite");
			self::same(['/young'], array_column(iterator_to_array((function () use ($db) {
				$r = $db->query("SELECT path FROM kizami_events WHERE type = 'page'");
				while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
					yield $row;
				}
			})(), false), 'path'), 'Default: a 12-month-old page event stays');
			self::same(['young.pdf'], array_column(iterator_to_array((function () use ($db) {
				$r = $db->query('SELECT detail FROM kizami_custom_events');
				while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
					yield $row;
				}
			})(), false), 'detail'), 'Default: a 14-month-old action is deleted, a 12-month-old one stays');
			self::same(1, $db->querySingle('SELECT views FROM kizami_days'), 'Default: the rolled-up page is kept as a daily total');
			$db->close();

			// The option raises the period: nothing is rolled up at 14 months.
			$long = new Store("$tmp/long.sqlite", null, [], 60);
			$long->write(['type' => 'page', 'action' => 'view', 'path' => '/old', 'session' => 'o', 'created_at' => $ago('-14 months -1 day')]);
			$long->write(['type' => 'event', 'event' => 'download', 'detail' => 'old.pdf', 'session' => 'o', 'created_at' => $ago('-14 months -1 day')]);
			self::same(0, $long->compact(), 'Option 60: nothing is rolled up at 14 months');
			$db = new SQLite3("$tmp/long.sqlite");
			self::same(1, $db->querySingle('SELECT COUNT(*) FROM kizami_custom_events'), 'Option 60: the action is kept');
			$db->close();

			// Validation: a whole number from 1 to 600, otherwise null (the caller
			// logs and uses the default).
			foreach ([[13, 13], ['13', 13], ['024', 24], [1, 1], [600, 600], [0, null], [601, null], [-1, null], [13.0, null], ['13.0', null],
				['', null], [' 13', null], ['1e1', null], ['13 ', null], ['monthly', null], [true, null], [false, null], [[13], null],
				['99999999999999999999', null], [null, null]] as [$value, $expected]) {
				self::same($expected, Store::validRetentionMonths($value), 'Validation of ' . var_export($value, true));
			}
			self::same(13, Store::RETENTION_MONTHS, 'Default constant');
			self::same(600, Store::RETENTION_MONTHS_MAX, 'Cap constant');

			// Option read with real Kirby: unset is the default without a log line,
			// an invalid value is logged and the default is used.
			$kirbyWith = function (array $options) use ($tmp): Kirby\Cms\App {
				return new Kirby\Cms\App([
					'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
						'site' => $tmp . '/site', 'storage' => $tmp . '/storage'],
					'options' => array_merge(['url' => 'https://example.invalid/'], $options),
				]);
			};
			$logged = fn () => is_file($logFile) ? (string)file_get_contents($logFile) : '';
			$before = strlen($logged());
			$app = $kirbyWith([]);
			self::same(13, Options::retentionMonths($app), 'Option unset: 13 months');
			self::same($before, strlen($logged()), 'Option unset: nothing logged');
			Kirby\Cms\App::destroy();
			foreach ([[14, 14], ['24', 24], [60, 60]] as [$value, $expected]) {
				$app = $kirbyWith(['kizami.retentionMonths' => $value]);
				self::same($expected, Options::retentionMonths($app), 'Option ' . var_export($value, true));
				Kirby\Cms\App::destroy();
			}
			foreach (['monthly', 0, 601, 13.5, false] as $value) {
				$before = strlen($logged());
				$app = $kirbyWith(['kizami.retentionMonths' => $value]);
				self::same(13, Options::retentionMonths($app), 'Invalid option ' . var_export($value, true) . ': default');
				Kirby\Cms\App::destroy();
				self::same(true, str_contains(substr($logged(), $before), 'kizami.retentionMonths'), 'Invalid option ' . var_export($value, true) . ': logged');
			}

			// Report: the comparison of a day or week may not reach before the limit
			// of single events. Today is 01.10.2026, so the limit is 01.09.2025.
			$thu = new DateTimeImmutable('2026-10-01 09:00', $berlin);
			$refDay = fn (string|null $date, string $kind = 'day', int $months = 13) => Report::referenceDay($kind, $date, $thu, $months)->format('Y-m-d H:i');
			self::same('2025-09-09 00:00', $refDay('2025-09-08'), 'Comparison day lies exactly on the limit');
			self::same('2025-09-22 00:00', $refDay('2025-09-15', 'week'), 'Comparison week starts after the limit');
			self::same('2025-09-15 00:00', $refDay('2025-09-08', 'week'), 'Comparison week starts exactly on the limit');
			foreach ([['day', '2025-09-07'], ['week', '2025-09-01']] as [$kind, $date]) {
				try {
					Report::referenceDay($kind, $date, $thu);
					throw new RuntimeException("$kind $date before the limit was accepted");
				} catch (InvalidArgumentException $e) {
					self::same(true, str_contains($e->getMessage(), '13 months'), "$kind $date: message names the retention");
				}
				$n++;
				Assert::assertTrue(true);
			}
			self::same('2025-09-08 00:00', $refDay('2025-09-07', 'day', 60), 'Option 60: the day before 13 months is accepted');

			// The CLI job has no Kirby options: the flag carries the value. Its
			// argument is the storage directory, not the file.
			$cli = function (array $args) use ($base): array {
				$process = proc_open([PHP_BINARY, $base . '/maintenance.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
				$out = stream_get_contents($pipes[1]);
				fclose($pipes[1]);
				$err = stream_get_contents($pipes[2]);
				fclose($pipes[2]);
				return [proc_close($process), trim($out . $err)];
			};
			$cliDir = "$tmp/cli";
			mkdir($cliDir, 0700);
			$fixture = function () use ($cliDir, $ago): void {
				foreach (glob("$cliDir/*") ?: [] as $f) {
					@unlink($f);
				}
				$store = new Store("$cliDir/" . Store::FILE, null, [], 60);
				$store->write(['type' => 'page', 'action' => 'view', 'path' => '/old', 'session' => 'o', 'created_at' => $ago('-14 months -1 day')]);
				$store->write(['type' => 'event', 'event' => 'download', 'detail' => 'old.pdf', 'session' => 'o', 'created_at' => $ago('-14 months -1 day')]);
			};
			[$code, $text] = $cli(['--retention-months=0', 'compact', $cliDir]);
			self::same([1, true], [$code, str_contains($text, '--retention-months must be a whole number')], 'CLI: invalid flag refused');
			$fixture();
			[$code, $text] = $cli(['--retention-months=60', 'compact', $cliDir]);
			self::same([0, true], [$code, str_contains($text, '0 old single events')], 'CLI: flag 60 keeps the 14-month-old event: ' . $text);
			[$code, $text] = $cli(['compact', $cliDir]);
			self::same([0, true], [$code, str_contains($text, '1 old single events')], 'CLI: without the flag the default (13 months) rolls up the 14-month-old event: ' . $text);
			$db = new SQLite3("$cliDir/" . Store::FILE);
			self::same([1, 0], [$db->querySingle('SELECT COUNT(*) FROM kizami_days'), $db->querySingle('SELECT COUNT(*) FROM kizami_custom_events')], 'CLI: rolled up, the 14-month-old action is deleted');
			$db->close();

		} catch (Throwable $e) {
			throw $e;
		} finally {
			Kirby\Cms\App::destroy();
			@unlink($logFile);
			foreach (glob("$tmp/*.sqlite*") ?: [] as $f) {
				@unlink($f);
			}
			foreach (glob("$tmp/cli/*") ?: [] as $f) {
				@unlink($f);
			}
			@rmdir("$tmp/cli");
			@rmdir($tmp);
		}
	}
}
