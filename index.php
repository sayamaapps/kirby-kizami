<?php

use Kirby\Filesystem\F;
use Sayamaapps\Kizami\Migration;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

/**
 * Kizami — server-side page counting, off by default.
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under
 * sayamaapps.kizami.active otherwise).
 */

// Old copy still there? See Migration::oldPluginLoaded().
if (Migration::oldPluginLoaded() === true) {
	return;
}

Kirby::plugin(
	name: 'sayamaapps/kizami',
	extends: [
		'options'      => require __DIR__ . '/config/options.php',
		'blueprints'   => require __DIR__ . '/config/blueprints.php',
		'translations' => require __DIR__ . '/config/translations.php',
		'snippets'     => require __DIR__ . '/config/snippets.php',
		'areas'        => require __DIR__ . '/config/areas.php',
		'pageMethods'  => require __DIR__ . '/config/page-methods.php',
		'hooks'        => require __DIR__ . '/config/hooks.php',
		'routes'       => require __DIR__ . '/config/routes.php',
	],
	root: __DIR__
);
