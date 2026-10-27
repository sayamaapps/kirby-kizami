<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\Runtime;

/**
 * Panel menu entry "Statistics" / "Kennzahlen" — the Panel is where
 * people look for it (since 1.1.0).
 *
 * A plain link area: no view, nothing to build into the Panel bundle.
 * Kirby's menu takes `link` and `target` straight from the area, so the
 * entry opens the dashboard in a new tab like any external menu link.
 * Every Panel role sees it: a logged-in Panel user already passes the
 * dashboard's access check (Panel user, see config/routes.php). During the build
 * phase (`sayamaapps.kizami.previewAccess`) the dashboard still asks for the
 * preview login.
 *
 * `menu` is a closure, so switching `sayamaapps.kizami.active` off removes the entry
 * without a cache clear. Sites with their own `panel.menu` list must add
 * 'kizami' to it — Kirby then shows only the listed areas.
 */
return [
	'kizami' => fn () => [
		'label' => 'kizami.dashboard.title',
		'icon'  => 'chart',
		'link'  => App::instance()->url('index') . '/k/dashboard',
		'target' => '_blank',
		'menu'  => fn () => Runtime::option(App::instance(), 'active', false) === true,
	],
];
