<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The class map in autoload.php matches src/: one entry per file, the file
 * name is the class name, and every class loads through the map. The legacy
 * names stay aliases of the new classes; the two exception classes keep their
 * 1.x names.
 */
#[CoversNothing]
final class AutoloadTest extends TestCase
{
	public function testEveryEntryLoadsItsClass(): void
	{
		$root = dirname(__DIR__);
		foreach (require $root . '/autoload.php' as $class => $path) {
			$this->assertFileExists($root . '/' . $path);
			$this->assertTrue(class_exists($class), 'class ' . $class . ' loads through the map');
		}
	}

	public function testLegacyNamesAreAliasesOfTheNewClasses(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		foreach (require dirname(__DIR__) . '/autoload.php' as $class => $path) {
			if (str_starts_with($class, 'kizami\\')) {
				// The exception classes keep their 1.x names; they have no new name.
				continue;
			}
			$short = substr($class, strlen('sayamaapps\\kizami\\'));
			if (in_array($short, ['hooks', 'pagemethods', 'routes', 'runtime'], true)) {
				// Added after the 1.x API; no 1.x class name to alias.
				continue;
			}
			$this->assertSame(
				strtolower('Sayamaapps\\Kizami\\' . $short),
				strtolower((new ReflectionClass('Kizami\\' . ucfirst($short)))->getName()),
				'Kizami\\' . $short . ' is an alias of the new class'
			);
		}
	}

	/**
	 * Fresh process: nothing has used a 1.x name yet. instanceof and catch do not
	 * autoload, so every alias must already exist once legacy.php is loaded.
	 */
	#[RunInSeparateProcess]
	public function testLegacyNamesMatchWithoutPriorUse(): void
	{
		require dirname(__DIR__) . '/legacy.php';

		$store = (new ReflectionClass(\Sayamaapps\Kizami\Store::class))->newInstanceWithoutConstructor();
		$this->assertTrue($store instanceof \Kizami\Store);

		try {
			throw new \Kizami\SetupError('test');
		} catch (\Kizami\SetupError $e) {
			$this->assertInstanceOf(\Kizami\SetupError::class, $e);
		}

		foreach (require dirname(__DIR__) . '/autoload.php' as $class => $path) {
			if (str_starts_with($class, 'sayamaapps\\kizami\\') === false) {
				continue;
			}
			$short = substr($class, strlen('sayamaapps\\kizami\\'));
			if (in_array($short, ['hooks', 'pagemethods', 'routes', 'runtime'], true)) {
				// Added after the 1.x API; no 1.x name to keep.
				continue;
			}
			$this->assertTrue(
				class_exists('Kizami\\' . ucfirst($short), false),
				'Kizami\\' . ucfirst($short) . ' is registered without prior use'
			);
		}
	}

	public function testMapListsEveryClassFileAndNothingElse(): void
	{
		$root = dirname(__DIR__);
		$map = require $root . '/autoload.php';

		$expected = [];
		foreach (glob($root . '/src/*.php') as $file) {
			$name = basename($file, '.php');
			// The exception classes are declared in the 1.x namespace (see legacy.php).
			$namespace = in_array($name, ['SetupError', 'ReportError'], true) ? 'kizami\\' : 'sayamaapps\\kizami\\';
			$expected[strtolower($namespace . $name)] = 'src/' . $name . '.php';
		}
		ksort($expected);
		ksort($map);

		$this->assertSame($expected, $map);
	}
}
