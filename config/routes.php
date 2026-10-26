<?php

use Kirby\Cms\App;
use Kirby\Http\Response;
use Sayamaapps\Kizami\Analysis;
use Sayamaapps\Kizami\Capture;
use Sayamaapps\Kizami\Chart;
use Sayamaapps\Kizami\Events;
use Sayamaapps\Kizami\Fonts;
use Sayamaapps\Kizami\Gaps;
use Sayamaapps\Kizami\I18n;
use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Options;
use Sayamaapps\Kizami\Redirect;
use Sayamaapps\Kizami\ReportService;
use Sayamaapps\Kizami\Runtime;
use Sayamaapps\Kizami\SetupError;
use Sayamaapps\Kizami\SnapshotService;
use Sayamaapps\Kizami\Store;

// Routes as a closure, not an array: the redirect routes depend on the
// options, which Kirby reads before the plugin extensions are loaded, so
// option() works here. setSite() runs later, which is why the target is
// resolved inside the route and not at registration.
return function () {
	$routes = [
		[
			'pattern' => 'k/image',
			'method' => 'POST',
			'action' => function () {
				// Only explicitly allowed image IDs and own browser requests.
				// No file paths from the request are opened, no cookies or identifiers sent.
				$done = fn () => new Response('', 'text/plain', 204, ['Cache-Control' => 'no-store']);
				if (Runtime::option('active', false) !== true || !(array)Runtime::option('images', [])) {
					return Runtime::notFound();
				}
				$path = Runtime::beaconPath();
				$image = get('image');
				if ($path === null || !is_string($image) || !array_key_exists($image, (array)Runtime::option('images', []))) {
					return $done();
				}
				Runtime::write(fn () => ['type' => 'image', 'action' => 'click', 'target' => $image, 'path' => $path]);
				return $done();
			},
		],
		[
			'pattern' => 'k/duration',
			'method' => 'POST',
			'action' => function () {
				// The same origin and path check as k/image; the dwell-time
				// script sends the same request shape as the gallery script.
				// Always 204 — the browser never learns whether anything was written.
				$done = fn () => new Response('', 'text/plain', 204, ['Cache-Control' => 'no-store']);
				if (Runtime::option('active', false) !== true || Runtime::option('dwellTime', false) !== true) {
					return Runtime::notFound();
				}
				$path = Runtime::beaconPath();
				$raw = get('seconds');
				// Unsigned integer, no decimals: anything else is no
				// plausible number of seconds and is discarded, not guessed.
				if ($path === null || !is_string($raw) || !preg_match('/^\d+$/', $raw)) {
					return $done();
				}
				$seconds = (int)$raw;
				if ($seconds < 1) {
					return $done();
				}
				// Over 30 minutes on one page is no measurement error to
				// discard but a cap — otherwise a manipulated value could
				// distort the median of a whole page.
				$seconds = min($seconds, 1800);
				Runtime::write(fn () => ['type' => 'duration', 'action' => 'visible', 'path' => $path, 'seconds' => $seconds]);
				return $done();
			},
		],
		[
			'pattern' => 'k/event',
			'method' => 'POST',
			'action' => function () {
				// Reported actions (kizami.events, since 1.4.0): the same
				// origin, path and opt-out rules as k/image, only listed
				// keys, always 204. The detail is the only free text any
				// Kizami route accepts, hence the size cap: a request with
				// more than 2 KB of data is discarded before any field is
				// looked at. No rate limit of its own, like the other beacons.
				$done = fn () => new Response('', 'text/plain', 204, ['Cache-Control' => 'no-store']);
				$events = Events::config(Runtime::option('events', []));
				if (Runtime::option('active', false) !== true || $events === []) {
					return Runtime::notFound();
				}
				if (Capture::requestTooLarge($_SERVER, 2048)) {
					return $done();
				}
				$path = Runtime::beaconPath();
				$event = get('event');
				if ($path === null || !Events::isKey($event) || !array_key_exists($event, $events)) {
					return $done();
				}
				// Without 'detail' => true a detail sent along is dropped, not stored.
				$detail = $events[$event]['detail'] ? Capture::cleanDetail(get('detail')) : '';
				Runtime::write(fn () => ['type' => 'event', 'event' => $event, 'detail' => $detail, 'path' => $path]);
				return $done();
			},
		],
		[
			/**
			 * The browser half of the actions as a file of its own: a
			 * strict CSP ("script-src 'self'") drops inline scripts, so
			 * there are none. Embed it with the snippet `kizami/events`.
			 *
			 * A route and not the plugin's assets/ folder: Kirby would
			 * publish that under /media for every site, even with the
			 * module off. This way the file only exists while actions are
			 * configured — otherwise Kirby's real 404, like the other
			 * routes. Public, unlike dashboard.css: visitors load it.
			 */
			'pattern' => 'k/event.js',
			'method'  => 'GET',
			'action'  => function () {
				if (Runtime::option('active', false) !== true || Events::config(Runtime::option('events', [])) === []) {
					return Runtime::notFound();
				}
				return new Response((string)file_get_contents(dirname(__DIR__) . '/views/event.js'), 'text/javascript', 200, [
					'Cache-Control' => 'public, max-age=86400',
					'X-Content-Type-Options' => 'nosniff',
				]);
			},
		],
		[
			'pattern' => 'k/dashboard',
			'method'  => 'GET',
			'action'  => function () {
				if (Runtime::option('active', false) !== true) {
					return Runtime::notFound();
				}

				if ($refused = Runtime::dashboardRefused()) {
					return $refused;
				}

				$days = (int)(get('days') ?? 30);
				if (!in_array($days, [7, 30, 90, 365], true)) {
					$days = 30;
				}

				$kirby = App::instance();
				I18n::setLanguage(Options::language($kirby));
				try {
					$store = Runtime::store();
					$store->compact();
					Runtime::rotateSecret();
					$names = new Names(
						(array)Runtime::option('targetNames', []),
						(array)Runtime::option('sourceNames', []),
						(array)Runtime::option('pageNames', []),
						// Page titles from Kirby, without Analysis knowing Kirby.
						fn (string $path) => $kirby->site()->find(trim($path, '/'))?->title()->value()
					);
					$redirects = array_keys((array)Runtime::option('redirects', []));
					$data = Analysis::data($store, $names, $days, [
						'brand'          => Options::brand($kirby),
						'targets'        => $redirects,
						'tiles'          => (array)Runtime::option('tiles', array_slice($redirects, 0, 2)),
						'contactTargets' => array_values(array_intersect((array)Runtime::option('contactTargets', []), $redirects)),
						'positionNames'  => (array)Runtime::option('positionNames', []),
						'images'         => (array)Runtime::option('images', []),
						'tileNames'      => (array)Runtime::option('tileNames', []),
						'color'          => (string)Runtime::option('color', '#2f4f2a'),
						'dwellTime'      => Runtime::option('dwellTime', false) === true,
						'events'         => Events::config(Runtime::option('events', [])),
						'gaps'           => Gaps::config(Runtime::option('gaps', [])),
					]);
				} catch (\Throwable $e) {
					error_log('Kizami: dashboard not available: ' . $e->getMessage());
					Runtime::logError($e);
					$data = ['days' => $days, 'error' => true, 'brand' => Options::brand($kirby)];
					if ($e instanceof SetupError) {
						$data['setup'] = $e->getMessage();
					}
				}

				// Under a subdirectory install /k/ lives below the index
				// URL. A path, not the full URL, like kizamiRedirectLink(): if the
				// `url` option differs from the host of the request or says
				// http://, an absolute link would load cross-origin or as
				// mixed content, and the browser would not send the login.
				$data['cssUrl'] = rtrim((string)parse_url($kirby->url('index'), PHP_URL_PATH), '/') . '/k/dashboard.css';

				$html = (function () use ($data) {
					ob_start();
					include dirname(__DIR__) . '/views/dashboard.php';
					return ob_get_clean();
				})();

				return new Response($html, 'text/html', empty($data['error']) ? 200 : 503, [
					'X-Robots-Tag'  => 'noindex, nofollow, noarchive',
					'Cache-Control' => 'private, no-store',
				]);
			},
		],
		[
			// Short report as JSON for scripts; switch, login and format
			// in src/ReportService.php.
			'pattern' => 'k/report',
			'method'  => 'GET',
			'action'  => fn () => ReportService::respond(
				App::instance(),
				new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'))
			),
		],
		[
			// Consistent database copy for backup jobs without a shell;
			// switch, login and reasoning in src/SnapshotService.php.
			'pattern' => 'k/snapshot',
			'method'  => 'GET',
			'action'  => fn () => SnapshotService::respond(App::instance()),
		],
		[
			/**
			 * The dashboard styles as their own file.
			 *
			 * Reason: a strict CSP is "style-src 'self'" without
			 * unsafe-inline. An embedded style block in dashboard.php
			 * would be dropped silently by the browser — the dashboard
			 * would arrive unstyled, without any error.
			 *
			 * Behind the same access as the dashboard, not open:
			 * otherwise requesting /k/dashboard.css would reveal that
			 * this module runs here — exactly the fingerprint the real
			 * 404 of the dashboard route avoids. Browsers send Basic Auth
			 * and the Panel cookie with same-origin subresources, so the
			 * styles arrive.
			 */
			'pattern' => 'k/dashboard.css',
			'method'  => 'GET',
			'action'  => function () {
				if (Runtime::option('active', false) !== true) {
					return Runtime::notFound();
				}
				if ($refused = Runtime::dashboardRefused()) {
					return $refused;
				}

				$base = (string)Runtime::option('color', '#2f4f2a');
				$css = (string)file_get_contents(dirname(__DIR__) . '/views/dashboard.css');
				if (preg_match('/^#[0-9a-f]{6}$/i', $base)) {
					$css .= "\n:root { --base: $base; --up: $base;";
					foreach (Chart::shades($base) as $i => $s) {
						$css .= ' --shade-' . ($i + 1) . ": $s;";
					}
					$css .= " }\n";
				}
				// Fonts from the configuration (src/Fonts.php) — the usual
				// reason for extra CSS, now without a file of its own.
				$fonts = Fonts::css(Runtime::option('fonts'));
				foreach ($fonts['errors'] as $message) {
					error_log('Kizami: ' . $message);
				}
				$css .= $fonts['css'] === '' ? '' : "\n" . $fonts['css'];
				// Project extras (fine-tuning): a file relative to the project root, loaded last — so it wins,
				// for example 'site/config/kizami-extra.css'. Fonts are better set via kizami.fonts.
				$extra = Runtime::option('extraCss');
				if (is_string($extra) && $extra !== '' && !str_contains($extra, '..') && str_ends_with($extra, '.css')) {
					$file = App::instance()->root('base') . '/' . ltrim($extra, '/');
					if (is_file($file)) {
						$css .= "\n" . file_get_contents($file);
					}
				}
				return new Response($css, 'text/css', 200, ['X-Robots-Tag' => 'noindex, nofollow']);
			},
		],
	];

	/**
	 * Redirects — DELIBERATELY NOT tied to `kizami.active`.
	 *
	 * Tying them to the same switch as the counting would mean: switching
	 * off the statistics breaks EVERY phone link on the site — invisibly
	 * for whoever flipped the switch.
	 *
	 * So the split is: the redirect is a function of the SITE and depends
	 * only on a configured target. Counting is optional and depends on
	 * `active` (checked in Runtime::write()). "Without configuration
	 * nothing happens" still holds: without `redirects` no route is
	 * registered here.
	 */
	foreach (Runtime::option('redirects', []) as $rawName => $entry) {
		$name = Redirect::cleanName((string)$rawName);
		if ($name === '') {
			continue;
		}

		$routes[] = [
			'pattern' => $name,
			'method'  => 'GET',
			'action'  => function () use ($name, $entry) {
				$target = Redirect::resolveTarget($entry);

				// Unknown or disallowed target: Kirby's real 404, no hint
				// that a module sits here.
				if (!Redirect::targetAllowed($target)) {
					error_log('Kizami: redirect "' . $name . '" has no allowed target');
					return Runtime::notFound();
				}

				// Count, then redirect. Runtime::write() never throws — so
				// the order is harmless. The other way round it wouldn't
				// be: after a redirect no code runs.
				Runtime::write(fn () => [
					'type'     => 'redirect',
					'action'   => 'click',
					'path'     => Capture::cleanPath(get('from')),
					'target'   => $name,
					'position' => is_string(get('position')) && array_key_exists(get('position'), (array)Runtime::option('positionNames', [])) ? get('position') : '',
				], fn (Store $store, string $session, \DateTimeImmutable $now) => Capture::linkClickCounts(
					App::instance()->request()->header('Sec-Fetch-User') === '?1',
					fn () => $store->hasPageView($session, $now)
				));

				// Response::redirect() is not used: it sends the target through
				// Url::unIdn(), and Uri::setScheme() only accepts http and https,
				// so a tel: target would throw. The header is set by hand here.
				//
				// 302, not 301: a permanent redirect would stick in the
				// browser and future clicks would never be counted again.
				//
				// X-Robots-Tag: search engines should neither index nor
				// follow the redirect; rel="nofollow" on the link itself
				// is set by the template (or $page->kizamiRedirectAnchor()).
				return new Response('', 'text/plain', 302, ['Location' => $target, 'X-Robots-Tag' => 'noindex, nofollow']);
			},
		];
	}

	return $routes;
};
