<?php

/**
 * views/event.js in Node with a minimal stand-in for the browser: which
 * path the script reports (data-kizami-path of its own tag, else
 * location.pathname), and that clicks, plays and window.kizami.event() all
 * send it. Skipped without Node (the SQLite container jobs have none).
 *   php tests/EventJsTest.php
 */

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Sayamaapps\Kizami\Events::class)]
#[RunTestsInSeparateProcesses]
final class EventJsTest extends TestCase
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

function run(attributes, pathname) {
  const listeners = {};
  const beacons = [];
  const script = {
    src: 'https://example.invalid/k/event.js?v=12345678',
    getAttribute: (name) => Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null,
  };
  const sandbox = {
    URLSearchParams,
    location: { pathname },
    navigator: { sendBeacon: (url, body) => { beacons.push({ url, body: Object.fromEntries(body) }); return true; } },
    document: { currentScript: script, addEventListener: (type, fn) => { listeners[type] = fn; } },
    fetch: () => { throw new Error('no fetch expected'); },
  };
  sandbox.window = sandbox;
  vm.runInNewContext(code, sandbox);
  const element = (event, detail, tag) => ({
    tagName: tag || 'A',
    getAttribute: (name) => ({ 'data-kizami-event': event, 'data-kizami-detail': detail })[name] ?? null,
    hasAttribute: (name) => name === 'data-kizami-event',
  });
  const link = element('download', 'book.pdf');
  link.closest = () => link;
  listeners.click({ type: 'click', target: link });
  listeners.play({ target: element('video_play', null, 'VIDEO') });
  sandbox.window.kizami.event('shortcode_access', 'manual');
  return beacons;
}

const cases = {
  attribute: run({ 'data-kizami-path': '/books/kizami' }, '/EEAQ77'),
  without: run({}, '/EEAQ77'),
  empty: run({ 'data-kizami-path': '' }, '/en/library'),
};
process.stdout.write(JSON.stringify(cases));
JS;
		$failed = false;
		$dir = sys_get_temp_dir() . '/kizami-eventjs-' . bin2hex(random_bytes(6));
		try {
			mkdir($dir, 0700);
			file_put_contents($dir . '/harness.js', $harness);
			$output = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($dir . '/harness.js') . ' ' . escapeshellarg(dirname(__DIR__) . '/views/event.js') . ' 2>&1');
			$cases = json_decode((string)$output, true);
			if (!is_array($cases)) {
				Assert::fail('Node harness failed: ' . $output);
			}
			$expect = function (string $case, string $path) use ($cases, &$n): void {
				$sent = $cases[$case] ?? [];
				$wanted = [
					['url' => 'https://example.invalid/k/event', 'body' => ['event' => 'download', 'path' => $path, 'detail' => 'book.pdf']],
					['url' => 'https://example.invalid/k/event', 'body' => ['event' => 'video_play', 'path' => $path]],
					['url' => 'https://example.invalid/k/event', 'body' => ['event' => 'shortcode_access', 'path' => $path, 'detail' => 'manual']],
				];
				if ($sent !== $wanted) {
					Assert::fail("$case: " . json_encode($sent));
				}
				Assert::assertTrue(true);
			};
			$expect('attribute', '/books/kizami'); // click, play and kizami.event() send the path of the tag
			$expect('without', '/EEAQ77');         // no attribute: location.pathname as before
			$expect('empty', '/en/library');       // an empty attribute is no path
		} catch (Throwable $e) {
			throw $e;
		} finally {
			exec('rm -rf ' . escapeshellarg($dir));
		}
		$this->assertFalse((bool)$failed, 'no failure');
	}
}
