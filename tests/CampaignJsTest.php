<?php

/**
 * views/campaign.js in Node with a minimal stand-in for the browser: which
 * links and fields take the tag of the page address, and that nothing happens
 * without a tag or a valid parameter. Skipped without Node (the SQLite
 * container jobs have none).
 *   vendor/bin/phpunit tests/CampaignJsTest.php
 */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Sayamaapps\Kizami\Campaign::class)]
#[RunTestsInSeparateProcesses]
final class CampaignJsTest extends TestCase
{
	public function testSuite(): void
	{
		$node = trim((string)shell_exec('command -v node 2>/dev/null'));
		if ($node === '') {
			$this->markTestSkipped('Node is not installed');
		}
		$harness = <<<'JS'
const vm = require('vm');
const fs = require('fs');
const code = fs.readFileSync(process.argv[2], 'utf8');

function anchor(href) {
  const attrs = { href };
  return {
    attrs,
    nodeType: 1,
    getAttribute: (name) => Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null,
    setAttribute: (name, value) => { attrs[name] = value; },
    matches: (selector) => selector === 'a[href]',
    querySelectorAll: () => [],
  };
}
function field(value) {
  return { value, nodeType: 1, matches: (selector) => selector === 'input[data-kizami-campaign]', querySelectorAll: () => [] };
}

function run(search, hrefs, param) {
  const anchors = hrefs.map(anchor);
  const fields = [field(''), field('keep')];
  let observer = null;
  const listeners = {};
  const document = {
    currentScript: { getAttribute: (name) => name === 'data-kizami-campaign' ? param : null },
    readyState: 'complete',
    documentElement: {},
    addEventListener: (type, fn) => { listeners[type] = fn; },
    querySelectorAll: (selector) => selector === 'a[href]' ? anchors : selector === 'input[data-kizami-campaign]' ? fields : [],
  };
  const page = new URL('https://example.com/page' + search);
  const sandbox = {
    URL, URLSearchParams, document,
    location: { href: page.href, search: page.search, origin: page.origin },
    MutationObserver: function (callback) { observer = callback; this.observe = () => {}; },
  };
  sandbox.window = sandbox;
  vm.runInNewContext(code, sandbox);
  const later = anchor('https://www.digistore24.com/product/1');
  const laterField = field('');
  if (observer) {
    observer([{ addedNodes: [later, { nodeType: 3 }, { nodeType: 1, querySelectorAll: (s) => s === 'input[data-kizami-campaign]' ? [laterField] : [] }] }]);
  }
  return {
    hrefs: anchors.map((a) => a.attrs.href),
    fields: fields.map((f) => f.value),
    later: later.attrs.href,
    laterField: laterField.value,
    campaign: sandbox.window.kizami ? sandbox.window.kizami.campaign : null,
    observed: observer !== null,
  };
}

const links = [
  'https://www.digistore24.com/product/525228?voucher=A&cam=portal-home',
  'https://www.digistore24.com/product/525228?aff=p&cam=k',
  'https://www.digistore24.com/product/525228#aff=p',
  'https://www.digistore24.com/product/525228?ds24tr=keep',
  'https://www.checkout-ds24.com/product/9#cam=old&x=1',
  'https://www.digistore24.com/redir/525228/partner/',
  '/termine',
  'https://example.com/buch?x=1#top',
  'https://other.example.org/x',
  '/already?cam=a',
  '/utm?utm_campaign=a',
  'mailto:hello@example.com',
  '#top',
  'tel:+491234567890',
  '//example.com/x',
];
const cases = {
  tagged: run('?cam=social-x', links, 'cam'),
  utm: run('?utm_campaign=autumn&cam=x', ['/termine'], 'cam'),
  cleaned: run('?cam=%3Cb%3Ea%20b', ['/termine'], 'cam'),
  none: run('?x=1', links, 'cam'),
  badParam: run('?cam=social-x', links, 'c m'),
};
process.stdout.write(JSON.stringify(cases));
JS;
		$dir = sys_get_temp_dir() . '/kizami-campaignjs-' . bin2hex(random_bytes(6));
		try {
			mkdir($dir, 0700);
			file_put_contents($dir . '/harness.js', $harness);
			$output = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($dir . '/harness.js') . ' ' . escapeshellarg(dirname(__DIR__) . '/views/campaign.js') . ' 2>&1');
			$cases = json_decode((string)$output, true);
			if (!is_array($cases)) {
				Assert::fail('Node harness failed: ' . $output);
			}
			$links = [
				'https://www.digistore24.com/product/525228?voucher=A&cam=portal-home',
				'https://www.digistore24.com/product/525228?aff=p&cam=k',
				'https://www.digistore24.com/product/525228#aff=p',
				'https://www.digistore24.com/product/525228?ds24tr=keep',
				'https://www.checkout-ds24.com/product/9#cam=old&x=1',
				'https://www.digistore24.com/redir/525228/partner/',
				'/termine',
				'https://example.com/buch?x=1#top',
				'https://other.example.org/x',
				'/already?cam=a',
				'/utm?utm_campaign=a',
				'mailto:hello@example.com',
				'#top',
				'tel:+491234567890',
				'//example.com/x',
			];
			$tagged = $cases['tagged'];
			Assert::assertSame([
				'https://www.digistore24.com/product/525228?voucher=A&ds24tr=social-x', // own cam dropped, ds24tr set
				'https://www.digistore24.com/product/525228?aff=p&cam=k',               // the affiliate's link
				'https://www.digistore24.com/product/525228#aff=p',                     // also in the fragment
				'https://www.digistore24.com/product/525228?ds24tr=keep',               // never replaced
				'https://www.checkout-ds24.com/product/9?ds24tr=social-x#x=1',          // cam out of the fragment
				'https://www.digistore24.com/redir/525228/partner/',                    // not an order link, another origin
				'/termine?cam=social-x',                                                // own link stays relative
				'https://example.com/buch?x=1&cam=social-x#top',
				'https://other.example.org/x',
				'/already?cam=a',
				'/utm?utm_campaign=a',
				'mailto:hello@example.com',
				'#top',
				'tel:+491234567890',
				'https://example.com/x?cam=social-x',                                   // same origin: absolute
			], $tagged['hrefs']);
			Assert::assertSame(['social-x', 'keep'], $tagged['fields'], 'empty field filled, a value kept');
			Assert::assertSame('https://www.digistore24.com/product/1?ds24tr=social-x', $tagged['later'], 'a link inserted later');
			Assert::assertSame('social-x', $tagged['laterField'], 'a field inserted later');
			Assert::assertSame('social-x', $tagged['campaign'], 'window.kizami.campaign');
			Assert::assertTrue($tagged['observed']);
			Assert::assertSame(['/termine?cam=autumn'], $cases['utm']['hrefs'], 'utm_campaign wins');
			Assert::assertSame(['/termine?cam=bab'], $cases['cleaned']['hrefs'], 'cleaned like on the server');
			foreach (['none', 'badParam'] as $case) {
				Assert::assertSame($links, $cases[$case]['hrefs'], $case . ': links untouched');
				Assert::assertSame(['', 'keep'], $cases[$case]['fields'], $case . ': fields untouched');
				Assert::assertNull($cases[$case]['campaign'], $case . ': no window.kizami');
				Assert::assertFalse($cases[$case]['observed'], $case . ': no observer');
			}
		} finally {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}
}
