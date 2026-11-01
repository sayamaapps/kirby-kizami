<?php

namespace Sayamaapps\Kizami;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Http\Response;
use Kizami\ReportError;
use Kizami\SetupError;
use Throwable;

/**
 * Runtime helpers of the plugin: option access, storage, the counting route
 * and the dashboard gate. Called from config/, views/ and snippets/. The
 * 1.x global functions (kizami_*) are wrappers in legacy.php.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Runtime
{
	/** Role of the Kirby accounts that open the dashboard with Basic Auth (since 2.0). */
	public const DASHBOARD_ROLE = 'kizami-dashboard';

	/**
	 * Access to the dashboard (`k/dashboard` and `k/dashboard.css`). Since 2.0
	 * Kirby's own accounts only, no password file of the plugin:
	 *
	 * - A logged-in Panel user is let in. The Panel login is Kirby's, with its
	 *   own failed-login limit (`auth.trials`, `auth.timeout`).
	 * - Otherwise Basic Auth against a Kirby account whose role is listed in
	 *   `sayamaapps.kizami.dashboardRoles` (default: the bundled role
	 *   `kizami-dashboard`). The password is checked by
	 *   `$kirby->auth()->validatePassword()`, so the same limit applies.
	 *
	 * Returns null when access is granted, otherwise the response to send: a 401
	 * with a login prompt when no login was given or it failed, a 403 when the
	 * account has no dashboard role. Nothing is written here.
	 */
	public static function accessDenied(App $kirby): Response|null
	{
		if ($kirby->user() !== null) {
			return null;
		}
		try {
			// The same check as the report and snapshot routes: Kirby's password
			// check and the role. HTTPS is checked before, in dashboardRefused().
			ReportService::authenticate($kirby, self::dashboardRoles($kirby));
		} catch (ReportError $e) {
			if ($e->status === 403) {
				return new Response('Not allowed for this account.', 'text/plain', 403, ['Cache-Control' => 'no-store']);
			}

			return self::deny();
		}

		return null;
	}

	/** Origin check for the beacons /k/image, /k/duration and /k/event, and the page they report. */
	public static function beaconPath(App $kirby): string|null
	{
		$url = parse_url($kirby->url('index'));
		$origin = ($url['scheme'] ?? '') . '://' . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
		$fetchSite = $kirby->request()->header('Sec-Fetch-Site');
		if (($kirby->request()->header('Origin') ?? '') !== $origin ||
			($fetchSite !== null && $fetchSite !== 'same-origin')) {
			return null;
		}
		$path = get('path');
		return self::findPage($kirby, $path) !== null ? $path : null;
	}

	/**
	 * Campaign tag of the request. utm_campaign wins; only if it is empty after
	 * cleaning, the parameter named in sayamaapps.kizami.campaignParam (for example `cam`)
	 * is used. Not set, or not a non-empty string: no fallback.
	 */
	public static function campaign(App $kirby): string
	{
		$campaign = Capture::cleanId(get('utm_campaign'));
		if ($campaign !== '') {
			return $campaign;
		}
		$param = self::option($kirby, 'campaignParam');
		if (!is_string($param) || $param === '') {
			return '';
		}
		return Capture::cleanId(get($param));
	}

	/** The campaign parameter (sayamaapps.kizami.campaignParam); null unless it is a usable name (since 2.1.0). */
	public static function campaignParam(App $kirby): string|null
	{
		return Campaign::param(self::option($kirby, 'campaignParam'));
	}

	/**
	 * A link with the campaign tag (since 2.1.0), see Campaign::link(): an order
	 * link takes it as ds24tr, a link of the site's own as the campaign parameter.
	 * $campaign null: the tag of this request (utm_campaign, else the campaign
	 * parameter). $hosts null: the site's own hosts (the index URL and
	 * sayamaapps.kizami.ownHosts).
	 *
	 * @param list<string>|null $hosts
	 */
	public static function campaignUrl(App $kirby, string $url, string|null $campaign = null, array|null $hosts = null): string
	{
		$hosts ??= array_values(array_filter(self::ownHosts($kirby), 'is_string'));

		return Campaign::link($url, $campaign ?? self::campaign($kirby), self::campaignParam($kirby), $hosts);
	}

	/**
	 * The dashboard's gate for /k/dashboard and /k/dashboard.css, after the check
	 * that the module is on: null = let in, otherwise the response to send.
	 *
	 * HTTPS first and before any login check: a Basic Auth password sent over
	 * plain HTTP travels in the clear, and a 401 with WWW-Authenticate would make
	 * the browser ask for exactly that. So over HTTP nobody is prompted and nobody
	 * is checked, the Panel login included. Same rule and same escape hatch
	 * (`sayamaapps.kizami.reportWithoutHttps`) as /k/report and /k/snapshot.
	 */
	public static function dashboardRefused(App $kirby): Response|null
	{
		if ($kirby->request()->ssl() === false && self::option($kirby, 'reportWithoutHttps', false) !== true) {
			return new Response('HTTPS only.', 'text/plain', 403, [
				'Cache-Control' => 'no-store',
				'X-Robots-Tag'  => 'noindex, nofollow, noarchive',
			]);
		}

		return self::accessDenied($kirby);
	}

	/**
	 * Roles of Kirby accounts that may open the dashboard with Basic Auth
	 * (`sayamaapps.kizami.dashboardRoles`).
	 *
	 * @return list<string>
	 */
	public static function dashboardRoles(App $kirby): array
	{
		return array_values(array_map('strval', (array)Options::get($kirby, 'dashboardRoles', [self::DASHBOARD_ROLE])));
	}

	/**
	 * The login prompt: the answer to every request that is not let in. Browsers
	 * show a login dialog only on a 401 with a challenge, so this is a real 401
	 * and not a 404.
	 */
	public static function deny(): Response
	{
		return new Response('Authentication required.', 'text/plain', 401, [
			'WWW-Authenticate' => 'Basic realm="Kizami", charset="UTF-8"',
			'Cache-Control'    => 'no-store',
		]);
	}

	/**
	 * Directory of the SQLite file and the secret, outside the web root. Throws
	 * SetupError for a missing storage root or one inside the web root
	 * (src/Setup.php). Moves the pre-1.0 directory storage/kennzahlen/ on first
	 * use (src/Migration.php).
	 */
	public static function directory(App $kirby): string
	{
		return Migration::storage(Setup::storageRoot($kirby->root('storage'), $kirby->root('index')));
	}

	/**
	 * Counts the configured action $key on $page for the current request, from
	 * PHP instead of the browser (since 1.4.0) — for a route or a template, for
	 * example a short-code route that counts `shortcode_access` without any
	 * JavaScript. Use $page->kizamiEvent($key, $detail) (page method below).
	 *
	 * The rules of /k/event, except the two that only make sense for a beacon
	 * (Origin, Sec-Fetch-Site): only keys listed in sayamaapps.kizami.events, a detail only
	 * for `'detail' => true` and cleaned like there, nothing for bots, an empty
	 * user agent, DNT or GPC (self::write()), the same day identifier as page
	 * views. HEAD requests count nothing: link checkers and previews send them.
	 * A key that is not (or no longer) configured counts nothing, silently —
	 * like a removed redirect, removing the entry is a way to switch it off.
	 *
	 * Never throws: errors end up in the error log, the page goes on.
	 */
	public static function event(App $kirby, mixed $key, mixed $page, mixed $detail = null): void
	{
		try {
			// sayamaapps.kizami.active, DNT/GPC and bots: self::write().
			if ($kirby->request()->method() === 'HEAD') {
				return;
			}
			$events = Events::config(self::option($kirby, 'events', []));
			$path = self::pagePath($page);
			if (!Events::isKey($key) || !array_key_exists($key, $events) || $path === null) {
				return;
			}
			$detail = $events[$key]['detail'] ? Capture::cleanDetail($detail) : '';
			self::write($kirby, fn () => ['type' => 'event', 'event' => $key, 'detail' => $detail, 'path' => $path]);
		} catch (Throwable $e) {
			error_log('Kizami: ' . $e->getMessage());
		}
	}

	/**
	 * The page a reported path names — only an existing page, never the error
	 * page. '/' is the home page, everything else goes through site()->find(),
	 * which takes the page ID in every language.
	 */
	public static function findPage(App $kirby, mixed $path): Page|null
	{
		if (!is_string($path) || strlen($path) > 255 || $path !== Capture::cleanPath($path) ||
			!str_starts_with($path, '/') || str_starts_with($path, '//')) {
			return null;
		}
		$page = $path === '/' ? $kirby->site()->homePage() : $kirby->site()->find(trim($path, '/'));
		return $page instanceof Page && !$page->isErrorPage() ? $page : null;
	}

	/**
	 * Also record dashboard and report errors in storage/kizami/errors.log.
	 *
	 * On some hosts error_log() ends up in no log anyone can read, which hides
	 * the cause of a dashboard failure. The file sits outside
	 * public/, contains only the exception text and location, no visitor data,
	 * and starts over at 64 KB.
	 */
	public static function logError(App $kirby, Throwable $e): void
	{
		try {
			$file = self::directory($kirby) . '/errors.log';
			$mode = is_file($file) && filesize($file) > 65536 ? 0 : FILE_APPEND;
			$line = date('c') . ' ' . $e::class . ': ' . $e->getMessage()
				. ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
			if ($e->getPrevious() !== null) {
				$line .= ' <- ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage();
			}
			// Record the versions too, so an old SQLite (3.7.17) can be told apart.
			$line .= ' [SQLite ' . (\SQLite3::version()['versionString'] ?? '?') . ', PHP ' . PHP_VERSION . ']';
			file_put_contents($file, $line . "\n", $mode | LOCK_EX);
		} catch (Throwable) {
			// Logging must never disturb the dashboard on top.
		}
	}

	/**
	 * A 503 with a pointer to the error log, for a broken setup. The dashboard no
	 * longer calls it; kept for the 1.x wrapper kizami_login_unavailable().
	 *
	 * @deprecated Removal planned for 2.0.
	 */
	public static function loginUnavailable(string $text): Response
	{
		return new Response($text, 'text/plain', 503, ['Cache-Control' => 'no-store']);
	}

	/** Kirby's real 404 — no format of its own that would reveal the module. */
	public static function notFound(App $kirby): Response
	{
		// Without content/error/, errorPage() returns null; then an empty 404 instead of a 500.
		$page = $kirby->site()->errorPage();
		return new Response($page?->render() ?? '', 'text/html', 404);
	}

	/** Reads `sayamaapps.kizami.<key>`, falling back to `kizami.<key>`. Also meant for site snippets (for example the dwell-time snippet). */
	public static function option(App $kirby, string $key, mixed $default = null): mixed
	{
		return Options::get($kirby, $key, $default);
	}

	/** The site's own hosts (index URL plus `sayamaapps.kizami.ownHosts`): referrers from them are internal, not a source. */
	public static function ownHosts(App $kirby): array
	{
		return [parse_url($kirby->url('index'), PHP_URL_HOST), ...(array)self::option($kirby, 'ownHosts', [])];
	}

	/**
	 * The path under which self::findPage() finds $page again (since 1.4.0):
	 * '/' for the home page, otherwise '/' and the page ID. The ID, not the
	 * address in the browser: a page served by a route of its own (a short code
	 * like /EEAQ77), under a language prefix or with a translated slug has
	 * another address, but one ID, and the beacons only accept existing pages.
	 * Null for no page, the error page and an ID the beacons would reject.
	 */
	public static function pagePath(mixed $page): string|null
	{
		if (!($page instanceof Page) || $page->isErrorPage()) {
			return null;
		}
		$path = $page->isHomePage() ? '/' : '/' . $page->id();
		return strlen($path) <= 255 && $path === Capture::cleanPath($path) ? $path : null;
	}

	/**
	 * The request values the capture rules read, taken from Kirby's request
	 * and environment objects instead of the superglobals.
	 *
	 * @return array<string,string|null>
	 */
	public static function requestValues(App $kirby): array
	{
		$request = $kirby->request();

		return [
			'HTTP_DNT' => $request->header('DNT'),
			'HTTP_SEC_GPC' => $request->header('Sec-GPC'),
			'HTTP_USER_AGENT' => $request->header('User-Agent'),
			'REMOTE_ADDR' => $kirby->environment()->get('REMOTE_ADDR'),
			'CONTENT_LENGTH' => $request->header('Content-Length'),
			'QUERY_STRING' => $kirby->environment()->get('QUERY_STRING'),
		];
	}

	/**
	 * Retire a stale daily key outside a counted visit (dashboard, report and
	 * snapshot after authentication). The Berlin day as in self::write(); never
	 * throws, a failure only reaches the error log.
	 */
	public static function rotateSecret(App $kirby): void
	{
		try {
			$day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
			if (!Secret::rotate(self::directory($kirby), $day)) {
				error_log('Kizami: stale daily key could not be replaced');
			}
		} catch (Throwable $e) {
			error_log('Kizami: daily key rotation failed: ' . $e->getMessage());
		}
	}

	/** Opens the site's database with the configured journal mode (`sayamaapps.kizami.journal`). */
	public static function store(App $kirby): Store
	{
		return new Store(self::directory($kirby) . '/' . Store::FILE, Store::journal(self::option($kirby, 'journal')), self::ownHosts($kirby), Options::retentionMonths($kirby));
	}

	/**
	 * Writes one row — and swallows every error.
	 *
	 * The row is only built inside the error handling. Neither caller (hook and
	 * redirect) may notice a broken database: the hook keeps rendering the page,
	 * the redirect keeps redirecting. That is why this helper catches \Throwable
	 * and returns nothing a caller could evaluate — there is nothing to decide.
	 */
	public static function write(App $kirby, array|callable $row, callable|null $counts = null): void
	{
		try {
			if (self::option($kirby, 'active', false) !== true) {
				return;
			}

			$now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
			$directory = self::directory($kirby);

			// A filtered request is still a request: yesterday's key must not
			// outlive the day because nobody was counted. Removed, not replaced:
			// no key is needed here.
			$server = self::requestValues($kirby);
			if (Capture::optedOut($server)) {
				Secret::expire($directory, $now->format('Y-m-d'));
				return;
			}
			$ua = $server['HTTP_USER_AGENT'] ?? '';
			if (Capture::isBot($ua)) {
				Secret::expire($directory, $now->format('Y-m-d'));
				return;
			}

			$row = is_callable($row) ? $row() : $row;

			// Pass the date: the secret rotates daily (see Secret.php). The same
			// zone as in the hash, otherwise secret and hash rotate at different
			// times.
			$secret = Secret::get($directory, $now->format('Y-m-d'));
			if ($secret === '') {
				return; // no secret, no capture
			}

			$store = new Store($directory . '/' . Store::FILE, Store::journal(self::option($kirby, 'journal')), [], Options::retentionMonths($kirby));
			$session = Capture::sessionHash(
				$server['REMOTE_ADDR'] ?? '0.0.0.0',
				$ua,
				$secret,
				$now
			);
			// Optional check of the caller (link filter of the redirects).
			// Discarding is never silent: the daily counter kizami_discarded
			// shows up in the dashboard and the report.
			if ($counts !== null && $counts($store, $session, $now) !== true) {
				$store->discard($now->format('Y-m-d'));
				return;
			}
			$store->write($row + [
				'created_at'   => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
				'target'       => '',
				'referrer'     => '',
				'utm_source'   => '',
				'utm_medium'   => '',
				'utm_campaign' => '',
				'session'      => $session,
				'device'       => Capture::deviceClass($ua),
			]);
		} catch (Throwable $e) {
			// A missing statistics row has no consequences. A broken page and a
			// lost phone call do.
			// A setup error's message already says "Kizami: …".
			error_log($e instanceof SetupError ? $e->getMessage() : 'Kizami: ' . $e->getMessage());
		}
	}
}
