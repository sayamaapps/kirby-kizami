<?php

use Kirby\Filesystem\F;
use Sayamaapps\Kizami\Migration;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

/**
 * Kizami — server-side page counting, off by default.
 *
 * The 'options' block in config/options.php declares every key with null only,
 * so the 1.x spelling kizami.<key> is still read as fallback. Defaults are
 * passed at each read.
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
