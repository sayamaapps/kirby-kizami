<?php

/** Direct link hits must create neither website visits nor a contact rate. */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Sayamaapps\Kizami\Analysis::class)]
#[CoversClass(\Sayamaapps\Kizami\Names::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class VisitsTest extends TestCase
{
	public function testSuite(): void
	{
		$dir = sys_get_temp_dir() . '/kizami-visits-' . bin2hex(random_bytes(6));
		mkdir($dir, 0700);
		try {
			$store = new Sayamaapps\Kizami\Store($dir . '/k.sqlite');
			$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
			$store = $store->atTime($today->setTime(12, 0));
			$put = function (string $type, string $hash, int $minute, string $target = '', int $day = 0) use ($store, $today) {
				$store->write(['type' => $type, 'action' => $type === 'page' ? 'view' : 'click',
					'session' => $hash, 'target' => $target, 'path' => '/', 'device' => 'mobile',
					'created_at' => $today->modify("$day days")->setTime(10, $minute)
						->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
			};
			for ($i = 0; $i < 10; $i++) {
				$put('redirect', 'scanner-' . $i, 0, 'call');
			}
			Assert::assertSame(0, $store->visits(7), 'Link scanners alone create no visits');
			Assert::assertSame(0, array_sum(array_column($store->visitsPerDay(7), 'count')), 'Daily trend stays without visits too');
			Assert::assertSame(0, array_sum($store->devices(7)), 'Link scanners do not change device shares');
			Assert::assertSame(0, $store->longTerm()['total']['devices'], 'Long term counts no scanners as visitors');

			$put('page', 'visitor', 0);
			$put('redirect', 'visitor', 1, 'call');
			$put('redirect', 'visitor', 2, 'call');
			$put('redirect', 'early', 0, 'call');
			$put('page', 'early', 1);
			$put('page', 'social', 0);
			$put('redirect', 'social', 1, 'instagram');
			$put('page', 'midnight', 0, '', -1);
			$put('redirect', 'midnight', 0, 'call');
			// The same identifier on two days counts two visits, but no click of
			// today may be attributed to yesterday's entry.
			$put('page', 'visitor', 0, '', -1);
			$put('page', 'previous', 0, '', -8);
			$put('redirect', 'previous', 1, 'call', -8);
			$put('redirect', 'scanner-old', 0, 'call', -8);
			Assert::assertSame(1, $store->visits(7, 1), 'Previous period also counts only page visits');
			Assert::assertSame(1, array_sum(array_column($store->contactSources(7, ['call'], 1)['sources'], 'withContact')), 'Contact attribution uses the previous-period window');
			Assert::assertSame(5, $store->visits(7), 'Visits count page identifiers per calendar day');
			Assert::assertSame(5, array_sum(array_column($store->visitsPerDay(7), 'count')), 'Daily series matches the headline number');
			Assert::assertSame(2, $store->visitsWithAction(7), 'Only later actions of the same day count');
			Assert::assertSame(12, array_sum(array_column($store->directLinkHits(7), 'count')), 'Scanners, early clicks and day changes counted separately');
			$store->write(['type' => 'page', 'action' => 'view', 'session' => 'visitor',
				'device' => '', 'created_at' => $today->setTime(10, 4)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
			Assert::assertSame(5, array_sum($store->devices(7)), 'Different metadata of the same day visit create no duplicate devices');
			$data = Sayamaapps\Kizami\Analysis::data(
				$store,
				new Sayamaapps\Kizami\Names([], [], []),
				7,
				['targets' => ['call', 'instagram'], 'contactTargets' => ['call']]
			);
			Assert::assertSame(20.0, $data['actionRate']['value'], 'Contact rate: one contact visit of five, social and scanners excluded');
			Assert::assertSame(100.0, $data['actionRate']['previous'], 'Previous-period rate with the same definition');
			Assert::assertSame(12, $data['directLinkHits']['total'], 'Direct hits shown separately in the dashboard');
			ob_start();
			include __DIR__ . '/../views/dashboard.php';
			$html = ob_get_clean();
			Assert::assertSame(true, str_contains($html, 'Link clicks without a previous page visit'), 'Separate section visible');
			Assert::assertSame(true, str_contains($html, 'Contact rate'), 'Rate named unambiguously');
		} finally {
			unset($store);
			foreach (glob($dir . '/*') as $file) {
				unlink($file);
			}
			rmdir($dir);
		}
	}
}
