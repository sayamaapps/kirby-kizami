<?php

use Kirby\Cms\App;
use Kirby\Filesystem\F;
use Kirby\Http\Response;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

use Sayamaapps\Kizami\Access;
use Sayamaapps\Kizami\Capture;
use Sayamaapps\Kizami\Events;
use Sayamaapps\Kizami\Lockout;
use Sayamaapps\Kizami\Migration;
use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Options;
use Sayamaapps\Kizami\Redirect;
use Sayamaapps\Kizami\Secret;
use Sayamaapps\Kizami\Setup;
use Sayamaapps\Kizami\SetupError;
use Sayamaapps\Kizami\Store;

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

/** Reads `kizami.<key>`. Also meant for site snippets (for example the dwell-time snippet). */
function kizami_option(string $key, mixed $default = null): mixed
{
	return Options::get(App::instance(), $key, $default);
}

/** Opens the site's database with the configured journal mode (`kizami.journal`). */
function kizami_store(): Store
{
	return new Store(kizami_directory() . '/' . Store::FILE, Store::journal(kizami_option('journal')), kizami_own_hosts(), Options::retentionMonths(App::instance()));
}

/** The site's own hosts (index URL plus `kizami.ownHosts`): referrers from them are internal, not a source. */
function kizami_own_hosts(): array
{
	return [parse_url(App::instance()->url('index'), PHP_URL_HOST), ...(array)kizami_option('ownHosts', [])];
}

/**
 * Directory of the SQLite file and the secret, outside the web root. Throws
 * SetupError for a missing storage root or one inside the web root
 * (src/Setup.php). Moves the pre-1.0 directory storage/kennzahlen/ on first
 * use (src/Migration.php).
 */
function kizami_directory(): string
{
	$kirby = App::instance();
	return Migration::storage(Setup::storageRoot($kirby->root('storage'), $kirby->root('index')));
}

/**
 * Retire a stale daily key outside a counted visit (dashboard, report and
 * snapshot after authentication). The Berlin day as in kizami_write(); never
 * throws, a failure only reaches the error log.
 */
function kizami_rotate_secret(): void
{
	try {
		$day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
		if (!Secret::rotate(kizami_directory(), $day)) {
			error_log('Kizami: stale daily key could not be replaced');
		}
	} catch (\Throwable $e) {
		error_log('Kizami: daily key rotation failed: ' . $e->getMessage());
	}
}

/**
 * Also record dashboard and report errors in storage/kizami/errors.log.
 *
 * On some hosts error_log() ends up in no log anyone can read, which hides
 * the cause of a dashboard failure. The file sits outside
 * public/, contains only the exception text and location, no visitor data,
 * and starts over at 64 KB.
 */
function kizami_log_error(\Throwable $e): void
{
	try {
		$file = kizami_directory() . '/errors.log';
		$mode = is_file($file) && filesize($file) > 65536 ? 0 : FILE_APPEND;
		$line = date('c') . ' ' . $e::class . ': ' . $e->getMessage()
			. ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
		if ($e->getPrevious() !== null) {
			$line .= ' <- ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage();
		}
		// Record the versions too, so an old SQLite (3.7.17) can be told apart.
		$line .= ' [SQLite ' . (\SQLite3::version()['versionString'] ?? '?') . ', PHP ' . PHP_VERSION . ']';
		@file_put_contents($file, $line . "\n", $mode | LOCK_EX);
	} catch (\Throwable) {
		// Logging must never disturb the dashboard on top.
	}
}

/**
 * Writes one row — and swallows every error.
 *
 * The row is only built inside the error handling. Neither caller (hook and
 * redirect) may notice a broken database: the hook keeps rendering the page,
 * the redirect keeps redirecting. That is why this helper catches \Throwable
 * and returns nothing a caller could evaluate — there is nothing to decide.
 */
function kizami_write(array|callable $row, callable|null $counts = null): void
{
	try {
		if (kizami_option('active', false) !== true) {
			return;
		}

		$now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
		$directory = kizami_directory();

		// A filtered request is still a request: yesterday's key must not
		// outlive the day because nobody was counted. Removed, not replaced:
		// no key is needed here.
		$server = $_SERVER;
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

		$store = new Store($directory . '/' . Store::FILE, Store::journal(kizami_option('journal')), [], Options::retentionMonths(App::instance()));
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
	} catch (\Throwable $e) {
		// A missing statistics row has no consequences. A broken page and a
		// lost phone call do.
		// A setup error's message already says "Kizami: …".
		error_log($e instanceof SetupError ? $e->getMessage() : 'Kizami: ' . $e->getMessage());
	}
}

/**
 * Campaign tag of the request. utm_campaign wins; only if it is empty after
 * cleaning, the parameter named in kizami.campaignParam (for example `cam`)
 * is used. Not set, or not a non-empty string: no fallback.
 */
function kizami_campaign(): string
{
	$campaign = Capture::cleanId(get('utm_campaign'));
	if ($campaign !== '') {
		return $campaign;
	}
	$param = kizami_option('campaignParam');
	if (!is_string($param) || $param === '') {
		return '';
	}
	return Capture::cleanId(get($param));
}

/** Origin check for the beacons /k/image, /k/duration and /k/event, and the page they report. */
function kizami_beacon_path(): string|null
{
	$url = parse_url(App::instance()->url('index'));
	$origin = ($url['scheme'] ?? '') . '://' . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
	if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $origin ||
		(isset($_SERVER['HTTP_SEC_FETCH_SITE']) && $_SERVER['HTTP_SEC_FETCH_SITE'] !== 'same-origin')) {
		return null;
	}
	$path = get('path');
	return kizami_find_page($path) !== null ? $path : null;
}

/**
 * The page a reported path names — only an existing page, never the error
 * page. '/' is the home page, everything else goes through site()->find(),
 * which takes the page ID in every language.
 */
function kizami_find_page(mixed $path): \Kirby\Cms\Page|null
{
	if (!is_string($path) || strlen($path) > 255 || $path !== Capture::cleanPath($path) ||
		!str_starts_with($path, '/') || str_starts_with($path, '//')) {
		return null;
	}
	$page = $path === '/' ? App::instance()->site()->homePage() : App::instance()->site()->find(trim($path, '/'));
	return $page instanceof \Kirby\Cms\Page && !$page->isErrorPage() ? $page : null;
}

/**
 * The path under which kizami_find_page() finds $page again (since 1.4.0):
 * '/' for the home page, otherwise '/' and the page ID. The ID, not the
 * address in the browser: a page served by a route of its own (a short code
 * like /EEAQ77), under a language prefix or with a translated slug has
 * another address, but one ID, and the beacons only accept existing pages.
 * Null for no page, the error page and an ID the beacons would reject.
 */
function kizami_page_path(mixed $page): string|null
{
	if (!($page instanceof \Kirby\Cms\Page) || $page->isErrorPage()) {
		return null;
	}
	$path = $page->isHomePage() ? '/' : '/' . $page->id();
	return strlen($path) <= 255 && $path === Capture::cleanPath($path) ? $path : null;
}

/**
 * Counts the configured action $key on $page for the current request, from
 * PHP instead of the browser (since 1.4.0) — for a route or a template, for
 * example a short-code route that counts `shortcode_access` without any
 * JavaScript. Use $page->kizamiEvent($key, $detail) (page method below).
 *
 * The rules of /k/event, except the two that only make sense for a beacon
 * (Origin, Sec-Fetch-Site): only keys listed in kizami.events, a detail only
 * for `'detail' => true` and cleaned like there, nothing for bots, an empty
 * user agent, DNT or GPC (kizami_write()), the same day identifier as page
 * views. HEAD requests count nothing: link checkers and previews send them.
 * A key that is not (or no longer) configured counts nothing, silently —
 * like a removed redirect, removing the entry is a way to switch it off.
 *
 * Never throws: errors end up in the error log, the page goes on.
 */
function kizami_event(mixed $key, mixed $page, mixed $detail = null): void
{
	try {
		// kizami.active, DNT/GPC and bots: kizami_write().
		if (App::instance()->request()->method() === 'HEAD') {
			return;
		}
		$events = Events::config(kizami_option('events', []));
		$path = kizami_page_path($page);
		if (!Events::isKey($key) || !array_key_exists($key, $events) || $path === null) {
			return;
		}
		$detail = $events[$key]['detail'] ? Capture::cleanDetail($detail) : '';
		kizami_write(fn () => ['type' => 'event', 'event' => $key, 'detail' => $detail, 'path' => $path]);
	} catch (\Throwable $e) {
		error_log('Kizami: ' . $e->getMessage());
	}
}

Kirby::plugin(
	name: 'sayamaapps/kizami',
	extends: [
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

/**
 * The dashboard's gate for /k/dashboard and /k/dashboard.css, after the check
 * that the module is on: null = let in, otherwise the response to send.
 *
 * HTTPS first and before any challenge: a Basic Auth password sent over plain
 * HTTP travels in the clear, and a 401 with WWW-Authenticate would make the
 * browser ask for exactly that. So over HTTP nobody is prompted and nobody is
 * checked — the Panel cookie included; the dashboard is HTTPS only for
 * everyone. Same rule and same escape hatch (`kizami.reportWithoutHttps`) as
 * /k/report and /k/snapshot. Where the dashboard would answer Kirby's 404
 * anyway (preview, no access file), that 404 stays: a 403 would give away
 * that the module runs here.
 */
function kizami_dashboard_refused(): Response|null
{
	$kirby = App::instance();
	if ($kirby->request()->ssl() === false && kizami_option('reportWithoutHttps', false) !== true) {
		$denial = kizami_deny();
		if ($denial->code() !== 401) {
			return $denial;
		}
		return new Response('HTTPS only.', 'text/plain', 403, [
			'Cache-Control' => 'no-store',
			'X-Robots-Tag'  => 'noindex, nofollow, noarchive',
		]);
	}
	return kizami_access_denied();
}

/**
 * Build phase vs. live:
 * - Build phase (`kizami.previewAccess` returns credentials, the site sits
 *   behind its own preview protection): check the same preview credentials
 *   again, independently. Being in preview alone is no proof of access. This
 *   resolves the Basic Auth realm collision (one Authorization header, two
 *   secrets).
 * - Live (`kizami.previewAccess` unset or null): own bcrypt auth from
 *   site/config/kizami.php OR Panel login.
 *
 * Returns null when access is granted, otherwise the response to send: the
 * usual denial (kizami_deny), or 429 once the failed-login limit is reached
 * (src/Lockout.php). Only submitted credentials that turn out wrong count;
 * a request without credentials (the browser's first visit) does not.
 */
function kizami_access_denied(): Response|null
{
	try {
		$preview = Options::previewCredentials(App::instance());
		if ($preview === null && App::instance()->user()) {
			return null;
		}
		$file = Options::accessFile(App::instance());
		$credentials = $preview ?? (file_exists($file) ? require $file : null);
	} catch (\Throwable $e) {
		error_log('Kizami: access configuration unusable');
		return kizami_deny();
	}

	$user = $_SERVER['PHP_AUTH_USER'] ?? null;
	$password = $_SERVER['PHP_AUTH_PW'] ?? null;
	// FastCGI sometimes passes Basic Auth only as HTTP_AUTHORIZATION.
	if ($user === null) {
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if (stripos($header, 'basic ') === 0) {
			$decoded = base64_decode(substr($header, 6), true);
			if ($decoded !== false && str_contains($decoded, ':')) {
				[$user, $password] = explode(':', $decoded, 2);
			}
		}
	}
	if ($user === null || $password === null) {
		return kizami_deny();
	}

	// Kirby's own limit would only fit Kirby accounts (see src/Lockout.php),
	// but its two options stay the one place to configure it.
	$kirby = App::instance();
	$ip = (string)$kirby->visitor()->ip();
	try {
		$directory = kizami_directory();
	} catch (SetupError $e) {
		// No usable storage, so no counters and no login: deny without
		// checking the password.
		error_log($e->getMessage());
		return kizami_login_unavailable('Kizami: setup error, see the error log.');
	} catch (\Throwable $e) {
		error_log('Kizami: login lockout file not usable: ' . $e->getMessage());
		return kizami_login_unavailable('Kizami: login limit unavailable, see the error log.');
	}
	try {
		$lockout = new Lockout(
			$directory . '/' . Lockout::FILE,
			max(1, (int)$kirby->option('auth.trials', 10)),
			max(1, (int)$kirby->option('auth.timeout', 3600))
		);
		// Checked before password_verify(): a blocked request costs the same
		// whatever it sends.
		$wait = $lockout->retryAfter($ip, $user);
	} catch (\Throwable $e) {
		error_log('Kizami: login lockout file not usable: ' . $e->getMessage());
		return kizami_login_unavailable('Kizami: login limit unavailable, see the error log.');
	}
	if ($wait > 0) {
		// Where the module hides behind Kirby's 404 (preview, no access file),
		// a 429 would give it away.
		$denial = kizami_deny();
		if ($denial->code() !== 401) {
			return $denial;
		}
		// Not another 401: the browser would ask again and never say why.
		return new Response('Too many failed logins. Try again later.', 'text/plain', 429, [
			'Retry-After'   => (string)max(1, $wait),
			'Cache-Control' => 'no-store',
		]);
	}

	if (Access::checkFile(is_array($credentials) ? $credentials : null, $user, $password)) {
		return null;
	}
	try {
		$lockout->fail($ip, $user);
	} catch (\Throwable $e) {
		error_log('Kizami: login lockout file not usable: ' . $e->getMessage());
	}
	return kizami_deny();
}

/**
 * No login possible right now (storage not set up, lockout file unusable):
 * where the file login would challenge (401), a 503 with a pointer to the
 * error log instead — a 401 would make the browser ask again for ever and
 * never say why; no path in the text, the visitor is not authenticated.
 * Where the module hides behind Kirby's 404 anyway (preview, no access
 * file), that 404 stays.
 */
function kizami_login_unavailable(string $text): Response
{
	$denial = kizami_deny();
	if ($denial->code() !== 401) {
		return $denial;
	}
	return new Response($text, 'text/plain', 503, ['Cache-Control' => 'no-store']);
}

/**
 * Denial without access.
 *
 * Once live with a valid site/config/kizami.php: a real 401 with a
 * challenge. Browsers ignore a 404 with a WWW-Authenticate header — no login
 * dialog would ever appear, the dashboard's own access would be unusable in a
 * normal browser. That reveals that something sits at /k/dashboard; content
 * still only comes with a valid login.
 *
 * Otherwise (preview, no own access set up, broken file): Kirby's real 404.
 * The preview asks for its credentials itself.
 */
function kizami_deny(): Response
{
	$access = Options::accessFile(App::instance());
	try {
		$preview = Options::previewCredentials(App::instance());
	} catch (\Throwable) {
		$preview = [];
	}
	if ($preview === null && file_exists($access)) {
		try {
			$credentials = require $access;
		} catch (\Throwable $e) {
			$credentials = null;
		}
		if (is_array($credentials) && is_string($credentials['user'] ?? null) && $credentials['user'] !== '' &&
			is_string($credentials['hash'] ?? null) && password_get_info($credentials['hash'])['algoName'] !== 'unknown') {
			return new Response('Authentication required.', 'text/plain', 401, [
				'WWW-Authenticate' => 'Basic realm="Kizami", charset="UTF-8"',
				'Cache-Control'    => 'no-store',
			]);
		}
	}
	return kizami_not_found();
}

/** Kirby's real 404 — no format of its own that would reveal the module. */
function kizami_not_found(): Response
{
	// Without content/error/, errorPage() returns null; then an empty 404 instead of a 500.
	$page = App::instance()->site()->errorPage();
	return new Response($page?->render() ?? '', 'text/html', 404);
}
