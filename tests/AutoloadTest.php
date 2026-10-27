<?php

use PHPUnit\Framework\Attributes\CoversNothing;
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
