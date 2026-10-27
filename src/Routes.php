<?php

namespace Sayamaapps\Kizami;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * The plugin's routes: the beacons (/k/image, /k/duration, /k/event), the
 * action script, the dashboard and its styles, and the redirects. Each route
 * is a method that takes the Kirby app. config/routes.php only wires them in.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Routes
{
	/**
	 * The route list for Kirby. The redirect routes depend on the options,
	 * which Kirby reads before the plugin extensions are loaded, so option()
	 * works here. setSite() runs later, which is why a redirect target is
	 * resolved inside the route and not at registration.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function all(App $kirby): array
	{
		$routes = [
			['pattern' => 'k/image', 'method' => 'POST', 'action' => fn () => Routes::imageClick($kirby)],
			['pattern' => 'k/duration', 'method' => 'POST', 'action' => fn () => Routes::durationClick($kirby)],
			['pattern' => 'k/event', 'method' => 'POST', 'action' => fn () => Routes::eventClick($kirby)],
			// The browser half of the actions as a file of its own: a strict CSP
			// ("script-src 'self'") drops inline scripts, so there are none. Embed
			// it with the snippet `kizami/events`. A route and not the plugin's
			// assets/ folder: Kirby would publish that under /media for every site,
			// even with the module off. This way the file only exists while actions
			// are configured. Public, unlike dashboard.css: visitors load it.
			['pattern' => 'k/event.js', 'method' => 'GET', 'action' => fn () => Routes::eventScript($kirby)],
			['pattern' => 'k/dashboard', 'method' => 'GET', 'action' => fn () => Routes::dashboard($kirby)],
			// Short report as JSON for scripts; switch, login and format in src/ReportService.php.
			['pattern' => 'k/report', 'method' => 'GET', 'action' => fn () => ReportService::respond(
				$kirby,
				new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'))
			)],
			// Consistent database copy for backup jobs without a shell; switch,
			// login and reasoning in src/SnapshotService.php.
			['pattern' => 'k/snapshot', 'method' => 'GET', 'action' => fn () => SnapshotService::respond($kirby)],
			// Behind the same access as the dashboard, not open: otherwise
			// requesting /k/dashboard.css would reveal that this module runs here.
			['pattern' => 'k/dashboard.css', 'method' => 'GET', 'action' => fn () => Routes::dashboardStyles($kirby)],
		];

		// Redirects are DELIBERATELY NOT tied to `sayamaapps.kizami.active`:
		// switching off the statistics must not break every phone link on the
		// site. Counting is optional and checked in Runtime::write(). Without
		// `redirects` no route is registered here.
		foreach (Runtime::option($kirby, 'redirects', []) as $rawName => $entry) {
			$name = Redirect::cleanName((string)$rawName);
			if ($name === '') {
				continue;
			}

			$routes[] = [
				'pattern' => $name,
				'method' => 'GET',
				'action' => fn () => Routes::redirect($kirby, $name, $entry),
			];
		}

		return $routes;
	}

	/** Only explicitly allowed image IDs and own browser requests; no file paths, cookies or identifiers. */
	public static function imageClick(App $kirby): Response
	{
		if (Runtime::option($kirby, 'active', false) !== true || !(array)Runtime::option($kirby, 'images', [])) {
			return Runtime::notFound($kirby);
		}
		$path = Runtime::beaconPath($kirby);
		$image = $kirby->request()->get('image');
		if ($path === null || !is_string($image) || !array_key_exists($image, (array)Runtime::option($kirby, 'images', []))) {
			return self::noContent();
		}
		Runtime::write($kirby, fn () => ['type' => 'image', 'action' => 'click', 'target' => $image, 'path' => $path]);

		return self::noContent();
	}

	/**
	 * The same origin and path check as k/image; the dwell-time script sends the
	 * same request shape as the gallery script. Always 204 — the browser never
	 * learns whether anything was written.
	 */
	public static function durationClick(App $kirby): Response
	{
		if (Runtime::option($kirby, 'active', false) !== true || Runtime::option($kirby, 'dwellTime', false) !== true) {
			return Runtime::notFound($kirby);
		}
		$path = Runtime::beaconPath($kirby);
		$raw = $kirby->request()->get('seconds');
		// Unsigned integer, no decimals: anything else is no plausible number of
		// seconds and is discarded, not guessed.
		if ($path === null || !is_string($raw) || !preg_match('/^\d+$/', $raw)) {
			return self::noContent();
		}
		$seconds = (int)$raw;
		if ($seconds < 1) {
			return self::noContent();
		}
		// Over 30 minutes on one page is a cap, not a measurement error to
		// discard: otherwise a manipulated value could distort the median of a
		// whole page.
		$seconds = min($seconds, 1800);
		Runtime::write($kirby, fn () => ['type' => 'duration', 'action' => 'visible', 'path' => $path, 'seconds' => $seconds]);

		return self::noContent();
	}

	/**
	 * Reported actions (sayamaapps.kizami.events, since 1.4.0): the same origin,
	 * path and opt-out rules as k/image, only listed keys, always 204. The detail
	 * is the only free text any Kizami route accepts, hence the size cap: a
	 * request with more than 2 KB of data is discarded before any field is looked
	 * at. No rate limit of its own, like the other beacons.
	 */
	public static function eventClick(App $kirby): Response
	{
		$events = Events::config(Runtime::option($kirby, 'events', []));
		if (Runtime::option($kirby, 'active', false) !== true || $events === []) {
			return Runtime::notFound($kirby);
		}
		if (Capture::requestTooLarge(Runtime::requestValues($kirby), 2048)) {
			return self::noContent();
		}
		$path = Runtime::beaconPath($kirby);
		$event = $kirby->request()->get('event');
		if ($path === null || !Events::isKey($event) || !array_key_exists($event, $events)) {
			return self::noContent();
		}
		// Without 'detail' => true a detail sent along is dropped, not stored.
		$detail = $events[$event]['detail'] ? Capture::cleanDetail($kirby->request()->get('detail')) : '';
		Runtime::write($kirby, fn () => ['type' => 'event', 'event' => $event, 'detail' => $detail, 'path' => $path]);

		return self::noContent();
	}

	/** The action script, only while actions are configured; otherwise Kirby's real 404. */
	public static function eventScript(App $kirby): Response
	{
		if (Runtime::option($kirby, 'active', false) !== true || Events::config(Runtime::option($kirby, 'events', [])) === []) {
			return Runtime::notFound($kirby);
		}

		return new Response((string)file_get_contents(dirname(__DIR__) . '/views/event.js'), 'text/javascript', 200, [
			'Cache-Control' => 'public, max-age=86400',
			'X-Content-Type-Options' => 'nosniff',
		]);
	}

	/**
	 * The dashboard. Under a subdirectory install /k/ lives below the index URL;
	 * the stylesheet link is a path, not the full URL, so the browser keeps the
	 * login on the same origin.
	 */
	public static function dashboard(App $kirby): Response
	{
		if (Runtime::option($kirby, 'active', false) !== true) {
			return Runtime::notFound($kirby);
		}
		if ($refused = Runtime::dashboardRefused($kirby)) {
			return $refused;
		}

		$days = (int)($kirby->request()->get('days') ?? 30);
		if (!in_array($days, [7, 30, 90, 365], true)) {
			$days = 30;
		}

		I18n::setLanguage(Options::language($kirby));
		try {
			$store = Runtime::store($kirby);
			$store->compact();
			Runtime::rotateSecret($kirby);
			$names = new Names(
				(array)Runtime::option($kirby, 'targetNames', []),
				(array)Runtime::option($kirby, 'sourceNames', []),
				(array)Runtime::option($kirby, 'pageNames', []),
				// Page titles from Kirby, without Analysis knowing Kirby.
				fn (string $path) => $kirby->site()->find(trim($path, '/'))?->title()->value()
			);
			$redirects = array_keys((array)Runtime::option($kirby, 'redirects', []));
			$data = Analysis::data($store, $names, $days, [
				'brand' => Options::brand($kirby),
				'targets' => $redirects,
				'tiles' => (array)Runtime::option($kirby, 'tiles', array_slice($redirects, 0, 2)),
				'contactTargets' => array_values(array_intersect((array)Runtime::option($kirby, 'contactTargets', []), $redirects)),
				'positionNames' => (array)Runtime::option($kirby, 'positionNames', []),
				'images' => (array)Runtime::option($kirby, 'images', []),
				'tileNames' => (array)Runtime::option($kirby, 'tileNames', []),
				'color' => (string)Runtime::option($kirby, 'color', '#2f4f2a'),
				'dwellTime' => Runtime::option($kirby, 'dwellTime', false) === true,
				'events' => Events::config(Runtime::option($kirby, 'events', [])),
				'gaps' => Gaps::config(Runtime::option($kirby, 'gaps', [])),
			]);
		} catch (Throwable $e) {
			error_log('Kizami: dashboard not available: ' . $e->getMessage());
			Runtime::logError($kirby, $e);
			$data = ['days' => $days, 'error' => true, 'brand' => Options::brand($kirby)];
			if ($e instanceof SetupError) {
				$data['setup'] = $e->getMessage();
			}
		}

		// A path, not the full URL, like the redirect link: if the `url` option
		// differs from the host of the request or says http://, an absolute link
		// would load cross-origin or as mixed content, and the browser would not
		// send the login.
		$data['cssUrl'] = rtrim((string)parse_url($kirby->url('index'), PHP_URL_PATH), '/') . '/k/dashboard.css';

		$html = (static function () use ($data): string {
			ob_start();
			include dirname(__DIR__) . '/views/dashboard.php';

			return (string)ob_get_clean();
		})();

		return new Response($html, 'text/html', empty($data['error']) ? 200 : 503, [
			'X-Robots-Tag' => 'noindex, nofollow, noarchive',
			'Cache-Control' => 'private, no-store',
		]);
	}

	/**
	 * The dashboard styles as their own file. A strict CSP is "style-src 'self'"
	 * without unsafe-inline, so an embedded style block would be dropped silently
	 * by the browser and the dashboard would arrive unstyled.
	 */
	public static function dashboardStyles(App $kirby): Response
	{
		if (Runtime::option($kirby, 'active', false) !== true) {
			return Runtime::notFound($kirby);
		}
		if ($refused = Runtime::dashboardRefused($kirby)) {
			return $refused;
		}

		$base = (string)Runtime::option($kirby, 'color', '#2f4f2a');
		$css = (string)file_get_contents(dirname(__DIR__) . '/views/dashboard.css');
		if (preg_match('/^#[0-9a-f]{6}$/i', $base)) {
			$css .= "\n:root { --base: $base; --up: $base;";
			foreach (Chart::shades($base) as $i => $s) {
				$css .= ' --shade-' . ($i + 1) . ": $s;";
			}
			$css .= " }\n";
		}
		// Fonts from the configuration (src/Fonts.php).
		$fonts = Fonts::css(Runtime::option($kirby, 'fonts'));
		foreach ($fonts['errors'] as $message) {
			error_log('Kizami: ' . $message);
		}
		$css .= $fonts['css'] === '' ? '' : "\n" . $fonts['css'];
		// Project extras (fine-tuning): a file relative to the project root, loaded
		// last, so it wins, for example 'site/config/kizami-extra.css'.
		$extra = Runtime::option($kirby, 'extraCss');
		if (is_string($extra) && $extra !== '' && !str_contains($extra, '..') && str_ends_with($extra, '.css')) {
			$file = $kirby->root('base') . '/' . ltrim($extra, '/');
			if (is_file($file)) {
				$css .= "\n" . file_get_contents($file);
			}
		}

		return new Response($css, 'text/css', 200, ['X-Robots-Tag' => 'noindex, nofollow']);
	}

	/**
	 * One configured redirect: count the hit, then send the visitor on.
	 *
	 * Unknown or disallowed target: Kirby's real 404, no hint that a module sits
	 * here. Count, then redirect: Runtime::write() never throws, and after a
	 * redirect no code runs.
	 *
	 * Response::redirect() is not used: it sends the target through Url::unIdn(),
	 * and Uri::setScheme() only accepts http and https, so a tel: target would
	 * throw. The header is set by hand here. 302, not 301: a permanent redirect
	 * would stick in the browser and future clicks would never be counted again.
	 * X-Robots-Tag: search engines should neither index nor follow the redirect;
	 * rel="nofollow" on the link itself is set by the template.
	 */
	public static function redirect(App $kirby, string $name, mixed $entry): Response
	{
		$target = Redirect::resolveTarget($entry);
		if (!Redirect::targetAllowed($target)) {
			error_log('Kizami: redirect "' . $name . '" has no allowed target');

			return Runtime::notFound($kirby);
		}

		$position = $kirby->request()->get('position');
		Runtime::write($kirby, fn () => [
			'type' => 'redirect',
			'action' => 'click',
			'path' => Capture::cleanPath($kirby->request()->get('from')),
			'target' => $name,
			'position' => is_string($position) && array_key_exists($position, (array)Runtime::option($kirby, 'positionNames', [])) ? $position : '',
		], fn (Store $store, string $session, DateTimeImmutable $now) => Capture::countsLinkClick(
			$kirby->request()->header('Sec-Fetch-User') === '?1',
			fn () => $store->hasPageView($session, $now)
		));

		return new Response('', 'text/plain', 302, ['Location' => $target, 'X-Robots-Tag' => 'noindex, nofollow']);
	}

	/** 204 without a body: the browser never learns whether anything was written. */
	private static function noContent(): Response
	{
		return new Response('', 'text/plain', 204, ['Cache-Control' => 'no-store']);
	}
}
