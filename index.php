<?php

use Kirby\Filesystem\F;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

use Sayamaapps\Kizami\Options;

/**
 * Kizami — server-side page counting, off by default.
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under
 * sayamaapps.kizami.active otherwise).
 */

/*
 * Old copy still there? If site/plugins/kennzahlen/ (the pre-1.0 copy of
 * this plugin) sits next to the package, Kirby loads both (alphabetically:
 * kennzahlen before kizami). Every request would count twice and the routes
 * would exist twice. In that case the package steps back and says so in the
 * error log — the old copy keeps running as before until someone deletes it.
 */
if (class_exists('Kennzahlen\\Erfassung', false)) {
	error_log('Kizami: site/plugins/kennzahlen/ still exists — the package stays inactive until the old copy is deleted.');
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
