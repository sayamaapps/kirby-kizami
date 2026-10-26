<?php

use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Options;

/**
 * Options are read as sayamaapps.kizami.<key>, with the 1.x spelling
 * kizami.<key> as fallback. The fallback is reported as a deprecation in
 * debug mode.
 */
#[CoversClass(Options::class)]
#[RunTestsInSeparateProcesses]
final class OptionsPrefixTest extends TestCase
{
	/** @param array<string, mixed> $options */
	private function app(array $options): App
	{
		return new App([
			'roots'   => ['index' => sys_get_temp_dir()],
			'options' => ['url' => 'http://example.invalid'] + $options,
		]);
	}

	public function testLegacyFunctionReadsTheNewOptionAndIsDeprecated(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		$messages = [];
		set_error_handler(function (int $level, string $message) use (&$messages): bool {
			$messages[] = $message;
			return true;
		}, E_USER_DEPRECATED);

		try {
			$this->app(['debug' => true, 'sayamaapps.kizami.active' => true]);
			$this->assertTrue(kizami_option('active'));
		} finally {
			restore_error_handler();
		}

		$this->assertSame(['kizami_option() is deprecated, use Sayamaapps\\Kizami\\Runtime::option()'], $messages);
	}

	public function testNewSpellingIsRead(): void
	{
		$app = $this->app(['sayamaapps.kizami.active' => true]);
		$this->assertTrue(Options::get($app, 'active'));
	}

	public function testNewSpellingWinsOverOld(): void
	{
		$app = $this->app(['sayamaapps.kizami.active' => false, 'kizami.active' => true]);
		$this->assertFalse(Options::get($app, 'active'));
	}

	public function testOldSpellingIsReadAndDeprecated(): void
	{
		$messages = [];
		set_error_handler(function (int $level, string $message) use (&$messages): bool {
			$messages[] = $message;
			return true;
		}, E_USER_DEPRECATED);

		try {
			$app = $this->app(['debug' => true, 'kizami.active' => true]);
			$this->assertTrue(Options::get($app, 'active'));
		} finally {
			restore_error_handler();
		}

		$this->assertSame(['The option kizami.active is deprecated, use sayamaapps.kizami.active'], $messages);
	}

	public function testUnsetFallsBackToTheDefault(): void
	{
		$app = $this->app([]);
		$this->assertSame('fallback', Options::get($app, 'active', 'fallback'));
		$this->assertNull(Options::get($app, 'active'));
	}
}
