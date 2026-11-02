<?php

/**
 * Campaign::link() without Kirby: order links take the tag as ds24tr, own
 * links as the campaign parameter, everything else stays as written.
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Campaign;

#[CoversClass(Campaign::class)]
final class CampaignTest extends TestCase
{
	public function testIsShop(): void
	{
		$this->assertTrue(Campaign::isShop('https://www.digistore24.com/product/123456'));
		$this->assertTrue(Campaign::isShop('https://checkout-ds24.com/product/123456/78901?x=1'));
		$this->assertTrue(Campaign::isShopHost('WWW.Digistore24.com'));
		$this->assertFalse(Campaign::isShop('https://www.digistore24.com/redir/123456/partner/'));
		$this->assertFalse(Campaign::isShop('https://example.com/product/1'));
		$this->assertFalse(Campaign::isShop('/product/1'));
		$this->assertFalse(Campaign::isShopHost('digistore24.com.example.com'));
	}

	public function testOrderLinks(): void
	{
		$cases = [
			// Own link without an affiliate: ds24tr; an own cam is dropped.
			'https://www.digistore24.com/product/123456' => 'https://www.digistore24.com/product/123456?ds24tr=portal-home',
			'https://www.checkout-ds24.com/product/123456?voucher=CODE&cam=portal-home' => 'https://www.checkout-ds24.com/product/123456?voucher=CODE&ds24tr=portal-home',
			'https://www.digistore24.com/product/123456/78901?cam=old' => 'https://www.digistore24.com/product/123456/78901?ds24tr=portal-home',
			'https://digistore24.com/product/123456?a=x%20y&b' => 'https://digistore24.com/product/123456?a=x%20y&b&ds24tr=portal-home',
			'https://www.digistore24.com/product/123456#cam=old' => 'https://www.digistore24.com/product/123456?ds24tr=portal-home',
			'https://www.digistore24.com/product/123456#cam=old&x=1' => 'https://www.digistore24.com/product/123456?ds24tr=portal-home#x=1',
			'https://www.digistore24.com/product/123456#top' => 'https://www.digistore24.com/product/123456?ds24tr=portal-home#top',
			// An existing tracking key stays, in the query or the fragment.
			'https://www.digistore24.com/product/123456?ds24tr=newsletter&cam=x' => 'https://www.digistore24.com/product/123456?ds24tr=newsletter',
			'https://www.digistore24.com/product/123456#ds24tr=newsletter' => 'https://www.digistore24.com/product/123456#ds24tr=newsletter',
			// An affiliate's link is untouched: cam belongs to the affiliate.
			'https://www.digistore24.com/product/123456?aff=partner&cam=story' => 'https://www.digistore24.com/product/123456?aff=partner&cam=story',
			'https://www.digistore24.com/product/123456?aff=partner' => 'https://www.digistore24.com/product/123456?aff=partner',
			'https://www.digistore24.com/product/123456#aff=partner&cam=story' => 'https://www.digistore24.com/product/123456#aff=partner&cam=story',
			// Not an order link: unchanged (no parameter given, so no own tag either).
			'https://www.digistore24.com/redir/123456/partner/' => 'https://www.digistore24.com/redir/123456/partner/',
			'https://www.digistore24.com/' => 'https://www.digistore24.com/',
			'https://digistore24.com.example.com/product/1' => 'https://digistore24.com.example.com/product/1',
			'https://notdigistore24.com/product/1' => 'https://notdigistore24.com/product/1',
		];
		foreach ($cases as $url => $expected) {
			$this->assertSame($expected, Campaign::link($url, 'portal-home'), $url);
		}
	}

	public function testOwnLinks(): void
	{
		$hosts = ['example.com', 'shop.example.com'];
		$cases = [
			'/events' => '/events?cam=portal-home',
			'/events?x=1#top' => '/events?x=1&cam=portal-home#top',
			'/' => '/?cam=portal-home',
			'https://example.com/events' => 'https://example.com/events?cam=portal-home',
			'https://EXAMPLE.com:8443/events' => 'https://EXAMPLE.com:8443/events?cam=portal-home',
			'http://shop.example.com' => 'http://shop.example.com?cam=portal-home',
			// Already tagged.
			'/events?cam=a' => '/events?cam=a',
			'/events?utm_campaign=a' => '/events?utm_campaign=a',
			// Another host, no link, or not a web link.
			'https://other.example.org/x' => 'https://other.example.org/x',
			'//example.com/x' => '//example.com/x',
			'#top' => '#top',
			'mailto:hello@example.com' => 'mailto:hello@example.com',
			'tel:+491234567890' => 'tel:+491234567890',
			'javascript:void(0)' => 'javascript:void(0)',
			'events' => 'events',
			'https://user:secret@example.com/x' => 'https://user:secret@example.com/x',
			'' => '',
		];
		foreach ($cases as $url => $expected) {
			$this->assertSame($expected, Campaign::link($url, 'portal-home', 'cam', $hosts), $url);
		}
		// Without hosts, every web host counts as own; without a parameter, no own link is tagged.
		$this->assertSame('https://other.example.org/x?cam=portal-home', Campaign::link('https://other.example.org/x', 'portal-home', 'cam'));
		$this->assertSame('/events', Campaign::link('/events', 'portal-home'));
		$this->assertSame('/events', Campaign::link('/events', 'portal-home', 'c m', $hosts));
	}

	public function testTagAndParameter(): void
	{
		// The tag is cleaned like a utm value; empty after cleaning: unchanged.
		$this->assertSame('/x?cam=babb', Campaign::link('/x', '<b>a b</b>', 'cam'));
		$this->assertSame('/x?cam=' . str_repeat('a', 50), Campaign::link('/x', str_repeat('a', 60), 'cam'));
		$this->assertSame('/x', Campaign::link('/x', '', 'cam'));
		$this->assertSame('/x', Campaign::link('/x', '<>', 'cam'));
		$this->assertSame('https://www.digistore24.com/product/1', Campaign::link('https://www.digistore24.com/product/1', ' '));
		$this->assertSame('cam', Campaign::param('cam'));
		$this->assertSame('k_1', Campaign::param('k_1'));
		$this->assertNull(Campaign::param(''));
		$this->assertNull(Campaign::param('c m'));
		$this->assertNull(Campaign::param(str_repeat('a', 33)));
		$this->assertNull(Campaign::param(['cam']));
		$this->assertNull(Campaign::param(null));
	}
}
