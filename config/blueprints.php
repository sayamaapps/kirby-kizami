<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\ReportService;
use Sayamaapps\Kizami\Runtime;
use Sayamaapps\Kizami\SnapshotService;

/**
 * Roles for the accounts that read /k/report and /k/snapshot — only with
 * the route switched on, otherwise they would appear in every site's
 * Panel role selection.
 *
 * option() already works here (optionsFromConfig() runs before
 * extensionsFromPlugins()). Kirby loads this file only ONCE per PHP
 * process, though: several App instances in one test see the roles as
 * the first one created them. On a server that is one request.
 */
return (Runtime::option('report', false) === true
		? ['users/' . ReportService::ROLE => dirname(__DIR__) . '/blueprints/kizami-report.yml']
		: [])
	+ (Runtime::option('snapshot', false) === true
		? ['users/' . SnapshotService::ROLE => dirname(__DIR__) . '/blueprints/kizami-snapshot.yml']
		: []);
