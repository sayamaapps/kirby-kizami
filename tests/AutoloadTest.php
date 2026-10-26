<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The class map in autoload.php matches src/: one entry per file, the file
 * name is the class name, and every class loads through the map. The legacy
 * names stay aliases of the new classes.
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
			$short = substr($class, strlen('sayamaapps\\kizami\\'));
			if ($short === 'runtime') {
				// Added with the function wrappers; it has no 1.x class name.
				continue;
			}
			$this->assertSame(
				strtolower('Sayamaapps\\Kizami\\' . $short),
				strtolower((new ReflectionClass('Kizami\\' . ucfirst($short)))->getName()),
				'Kizami\\' . $short . ' is an alias of the new class'
			);
		}
	}

	public function testMapListsEveryClassFileAndNothingElse(): void
	{
		$root = dirname(__DIR__);
		$map = require $root . '/autoload.php';

		$expected = [];
		foreach (glob($root . '/src/*.php') as $file) {
			$expected[strtolower('sayamaapps\\kizami\\' . basename($file, '.php'))] = 'src/' . basename($file);
		}

		$this->assertSame($expected, $map);
	}
}
