<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\Capture;
use Sayamaapps\Kizami\Runtime;

return [
	/**
	 * After every resolved route. Only real, rendered pages count as
	 * 'page' — not assets, not the Panel. A real 404 counts separately as
	 * 'notfound'. Capturing must NEVER damage the page: Runtime::write()
	 * doesn't throw.
	 *
	 * A real 404 has NO page object HERE yet: the page route
	 * (site/(:all)) returns `null` for a path it can't resolve, and
	 * App::io() only builds the error page AFTERWARDS from a
	 * NotFoundException (see App::resolve()/App::io()). Hence the special
	 * case via `$final` (last route pass) + empty `$result`, instead of
	 * `$result->isErrorPage()` — that would only catch someone calling the
	 * content page configured as error page by its real path.
	 */
	'route:after' => function ($route, $path, $method, $result, $final) {
		try {
			if ($method !== 'GET') {
				return;
			}
			// 404: the page route returns nothing, io() builds the error page later.
			if ($final && empty($result)) {
				Runtime::write(fn () => [
					'type'   => 'notfound',
					'action' => 'view',
					'path'   => Capture::cleanPath('/' . ltrim((string)$path, '/')),
				]);
				return;
			}
			if (!($result instanceof \Kirby\Cms\Page) || $result->isErrorPage()) {
				return;
			}
			Runtime::write(fn () => [
				'type'         => 'page',
				'action'       => 'view',
				'path'         => Capture::cleanPath('/' . ltrim((string)$path, '/')),
				'referrer'     => Capture::cleanReferrer(App::instance()->request()->header('Referer'), Runtime::ownHosts()),
				'utm_source'   => Capture::cleanId(get('utm_source')),
				'utm_medium'   => Capture::cleanId(get('utm_medium')),
				'utm_campaign' => Runtime::campaign(),
			]);
		} catch (\Throwable $e) {
			error_log('Kizami: ' . $e->getMessage());
		}
	},
];
