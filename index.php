<?php

use Kirby\Cms\App;
use Kirby\Http\Response;

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Capture.php';
require_once __DIR__ . '/src/Events.php';
require_once __DIR__ . '/src/Gaps.php';
require_once __DIR__ . '/src/Secret.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Migration.php';
require_once __DIR__ . '/src/Setup.php';
require_once __DIR__ . '/src/Redirect.php';
require_once __DIR__ . '/src/Access.php';
require_once __DIR__ . '/src/Lockout.php';
require_once __DIR__ . '/src/Names.php';
require_once __DIR__ . '/src/Comparison.php';
require_once __DIR__ . '/src/Chart.php';
require_once __DIR__ . '/src/Fonts.php';
require_once __DIR__ . '/src/Analysis.php';
require_once __DIR__ . '/src/Report.php';
require_once __DIR__ . '/src/ReportError.php';
require_once __DIR__ . '/src/ReportService.php';
require_once __DIR__ . '/src/SnapshotResponse.php';
require_once __DIR__ . '/src/SnapshotService.php';

use Kizami\Access;
use Kizami\Analysis;
use Kizami\Capture;
use Kizami\Chart;
use Kizami\Events;
use Kizami\Fonts;
use Kizami\Gaps;
use Kizami\I18n;
use Kizami\Lockout;
use Kizami\Migration;
use Kizami\Setup;
use Kizami\SetupError;
use Kizami\Names;
use Kizami\Options;
use Kizami\Redirect;
use Kizami\ReportService;
use Kizami\Secret;
use Kizami\SnapshotService;
use Kizami\Store;

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
    return [parse_url(App::instance()->url('index'), PHP_URL_HOST), ...(array) kizami_option('ownHosts', [])];
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
        $line = date('c') . ' ' . get_class($e) . ': ' . $e->getMessage()
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
function kizami_write(array|callable $row, ?callable $counts = null): void
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
function kizami_beacon_path(): ?string
{
    $url = parse_url(App::instance()->url('index'));
    $origin = ($url['scheme'] ?? '') . '://' . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $origin
        || (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && $_SERVER['HTTP_SEC_FETCH_SITE'] !== 'same-origin')) {
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
function kizami_find_page(mixed $path): ?\Kirby\Cms\Page
{
    if (!is_string($path) || strlen($path) > 255 || $path !== Capture::cleanPath($path)
        || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
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
function kizami_page_path(mixed $page): ?string
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

$kizamiTranslations = [];
foreach (I18n::LANGUAGES as $kizamiLanguage) {
    foreach (I18n::table($kizamiLanguage) as $kizamiKey => $kizamiText) {
        $kizamiTranslations[$kizamiLanguage]['kizami.' . $kizamiKey] = $kizamiText;
    }
}
unset($kizamiLanguage, $kizamiKey, $kizamiText);

App::plugin('sayamaapps/kizami', [
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
    'blueprints' => (kizami_option('report', false) === true
            ? ['users/' . ReportService::ROLE => __DIR__ . '/blueprints/kizami-report.yml']
            : [])
        + (kizami_option('snapshot', false) === true
            ? ['users/' . SnapshotService::ROLE => __DIR__ . '/blueprints/kizami-snapshot.yml']
            : []),

    // The dashboard and report strings, also usable as t('kizami.<key>').
    'translations' => $kizamiTranslations,

    // snippet('kizami/events') prints the script tag for the actions
    // (kizami.events), and nothing while they are off.
    'snippets' => ['kizami/events' => __DIR__ . '/snippets/events.php'],

    /**
     * Panel menu entry "Statistics" / "Kennzahlen" — the Panel is where
     * people look for it (since 1.1.0).
     *
     * A plain link area: no view, nothing to build into the Panel bundle.
     * Kirby's menu takes `link` and `target` straight from the area, so the
     * entry opens the dashboard in a new tab like any external menu link.
     * Every Panel role sees it: a logged-in Panel user already passes the
     * dashboard's access check (kizami_access_granted()). During the build
     * phase (`kizami.previewAccess`) the dashboard still asks for the
     * preview login.
     *
     * `menu` is a closure, so switching `kizami.active` off removes the entry
     * without a cache clear. Sites with their own `panel.menu` list must add
     * 'kizami' to it — Kirby then shows only the listed areas.
     */
    'areas' => [
        'kizami' => fn () => [
            'label' => 'kizami.dashboard.title',
            'icon'  => 'chart',
            'link'  => App::instance()->url('index') . '/k/dashboard',
            'target' => '_blank',
            'menu'  => fn () => kizami_option('active', false) === true,
        ],
    ],

    'pageMethods' => [
        /**
         * The URL of a configured redirect, with the current page as origin
         * — or the fallback if that redirect doesn't exist.
         *
         * The fallback is the point: a template writes
         *
         *     <a href="<?= $page->redirectLink('call', 'tel:+49…') ?>">
         *
         * and keeps a working phone link even if someone removes the config
         * entry. A link that leads nowhere because a statistics setting
         * changed would be an own goal — for a business that lives on phone
         * calls, an expensive one.
         *
         * No project terms here: `$name` is whatever the project configures.
         * The core doesn't know what a phone call is.
         */
        'redirectLink' => function (string $name, string $fallback = '#', string $position = '') {
            $targets = (array) kizami_option('redirects', []);
            $name = Redirect::cleanName($name);

            if ($name === '' || !array_key_exists($name, $targets)) {
                return $fallback;
            }

            // Origin page as an explicit parameter, not via the referrer:
            // that is empty or shortened depending on browser settings.
            //
            // The home page MUST be '/', not '/home'. The route:after hook
            // writes '/' for it (the path is empty there); if this said
            // '/home', the dashboard would show two names for one page.
            $origin = $this->isHomePage() ? '/' : '/' . $this->uri();

            // Under a subdirectory install the route lives below the index
            // URL; `from` stays the page path, so the statistics do not change.
            $base = rtrim((string) parse_url(App::instance()->url('index'), PHP_URL_PATH), '/');

            return $base . '/' . $name . '?from=' . rawurlencode($origin)
                . (array_key_exists($position, (array) kizami_option('positionNames', [])) ? '&position=' . rawurlencode($position) : '');
        },
        /**
         * Complete link with rel="nofollow" — for new templates. Link
         * followers that respect nofollow then don't trigger a redirect hit
         * at all. Templates using redirectLink() set rel="nofollow"
         * themselves.
         *
         *     <?= $page->redirectAnchor('call', 'Call us', 'tel:+49…', 'header', ['class' => 'button']) ?>
         *
         * $text is escaped; other rel values are kept.
         */
        'redirectAnchor' => function (string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []) {
            $rel = preg_split('/\s+/', trim((string) ($attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $rel[] = 'nofollow';
            unset($attributes['href'], $attributes['rel']);
            $attr = \Kirby\Toolkit\Html::attr(['href' => $this->redirectLink($name, $fallback, $position), 'rel' => implode(' ', array_unique($rel))] + $attributes);
            return '<a ' . $attr . '>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        },
        /**
         * Counts a configured action (kizami.events) on this page from PHP
         * (since 1.4.0), see kizami_event(). Returns the page, so a route
         * can count and answer in one line:
         *
         *     'action' => fn () => page('books/kizami')?->kizamiEvent('shortcode_access')
         *
         * Never throws and decides nothing: the page is returned whether
         * the action was counted or not.
         */
        'kizamiEvent' => function (mixed $key, mixed $detail = null) {
            kizami_event($key, $this, $detail);
            return $this;
        },
    ],

    'hooks' => [
        /**
         * After every resolved route. Only real, rendered pages count as
         * 'page' — not assets, not the Panel. A real 404 counts separately as
         * 'notfound'. Capturing must NEVER damage the page: kizami_write()
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
                    kizami_write(fn () => [
                        'type'   => 'notfound',
                        'action' => 'view',
                        'path'   => Capture::cleanPath('/' . ltrim((string) $path, '/')),
                    ]);
                    return;
                }
                if (!($result instanceof \Kirby\Cms\Page) || $result->isErrorPage()) {
                    return;
                }
                kizami_write(fn () => [
                    'type'         => 'page',
                    'action'       => 'view',
                    'path'         => Capture::cleanPath('/' . ltrim((string) $path, '/')),
                    'referrer'     => Capture::cleanReferrer($_SERVER['HTTP_REFERER'] ?? null, kizami_own_hosts()),
                    'utm_source'   => Capture::cleanId(get('utm_source')),
                    'utm_medium'   => Capture::cleanId(get('utm_medium')),
                    'utm_campaign' => kizami_campaign(),
                ]);
            } catch (\Throwable $e) {
                error_log('Kizami: ' . $e->getMessage());
            }
        },
    ],

    // Routes as a closure, not an array: the redirect routes are only known
    // once the config is read. Verified: optionsFromConfig()
    // runs BEFORE extensionsFromPlugins(), so option() works here. setSite()
    // runs AFTERWARDS, though — that is why the target is resolved in the
    // route, not at registration.
    'routes' => function () {
        $routes = [
            [
                'pattern' => 'k/image',
                'method' => 'POST',
                'action' => function () {
                    // Only explicitly allowed image IDs and own browser requests.
                    // No file paths from the request are opened, no cookies or identifiers sent.
                    $done = fn () => new Response('', 'text/plain', 204, ['Cache-Control' => 'no-store']);
                    if (kizami_option('active', false) !== true || !(array) kizami_option('images', [])) {
                        return kizami_not_found();
                    }
                    $path = kizami_beacon_path();
                    $image = get('image');
                    if ($path === null || !is_string($image) || !array_key_exists($image, (array) kizami_option('images', []))) {
                        return $done();
                    }
                    kizami_write(fn () => ['type' => 'image', 'action' => 'click', 'target' => $image, 'path' => $path]);
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
                    if (kizami_option('active', false) !== true || kizami_option('dwellTime', false) !== true) {
                        return kizami_not_found();
                    }
                    $path = kizami_beacon_path();
                    $raw = get('seconds');
                    // Unsigned integer, no decimals: anything else is no
                    // plausible number of seconds and is discarded, not guessed.
                    if ($path === null || !is_string($raw) || !preg_match('/^\d+$/', $raw)) {
                        return $done();
                    }
                    $seconds = (int) $raw;
                    if ($seconds < 1) {
                        return $done();
                    }
                    // Over 30 minutes on one page is no measurement error to
                    // discard but a cap — otherwise a manipulated value could
                    // distort the median of a whole page.
                    $seconds = min($seconds, 1800);
                    kizami_write(fn () => ['type' => 'duration', 'action' => 'visible', 'path' => $path, 'seconds' => $seconds]);
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
                    $events = Events::config(kizami_option('events', []));
                    if (kizami_option('active', false) !== true || $events === []) {
                        return kizami_not_found();
                    }
                    if (Capture::requestTooLarge($_SERVER, 2048)) {
                        return $done();
                    }
                    $path = kizami_beacon_path();
                    $event = get('event');
                    if ($path === null || !Events::isKey($event) || !array_key_exists($event, $events)) {
                        return $done();
                    }
                    // Without 'detail' => true a detail sent along is dropped, not stored.
                    $detail = $events[$event]['detail'] ? Capture::cleanDetail(get('detail')) : '';
                    kizami_write(fn () => ['type' => 'event', 'event' => $event, 'detail' => $detail, 'path' => $path]);
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
                    if (kizami_option('active', false) !== true || Events::config(kizami_option('events', [])) === []) {
                        return kizami_not_found();
                    }
                    return new Response((string) file_get_contents(__DIR__ . '/views/event.js'), 'text/javascript', 200, [
                        'Cache-Control' => 'public, max-age=86400',
                        'X-Content-Type-Options' => 'nosniff',
                    ]);
                },
            ],
            [
                'pattern' => 'k/dashboard',
                'method'  => 'GET',
                'action'  => function () {
                    if (kizami_option('active', false) !== true) {
                        return kizami_not_found();
                    }

                    if ($refused = kizami_dashboard_refused()) {
                        return $refused;
                    }

                    $days = (int) (get('days') ?? 30);
                    if (!in_array($days, [7, 30, 90, 365], true)) {
                        $days = 30;
                    }

                    $kirby = App::instance();
                    I18n::setLanguage(Options::language($kirby));
                    try {
                        $store = kizami_store();
                        $store->compact();
                        kizami_rotate_secret();
                        $names = new Names(
                            (array) kizami_option('targetNames', []),
                            (array) kizami_option('sourceNames', []),
                            (array) kizami_option('pageNames', []),
                            // Page titles from Kirby, without Analysis knowing Kirby.
                            fn (string $path) => $kirby->site()->find(trim($path, '/'))?->title()->value()
                        );
                        $redirects = array_keys((array) kizami_option('redirects', []));
                        $data = Analysis::data($store, $names, $days, [
                            'brand'          => Options::brand($kirby),
                            'targets'        => $redirects,
                            'tiles'          => (array) kizami_option('tiles', array_slice($redirects, 0, 2)),
                            'contactTargets' => array_values(array_intersect((array) kizami_option('contactTargets', []), $redirects)),
                            'positionNames'  => (array) kizami_option('positionNames', []),
                            'images'         => (array) kizami_option('images', []),
                            'tileNames'      => (array) kizami_option('tileNames', []),
                            'color'          => (string) kizami_option('color', '#2f4f2a'),
                            'dwellTime'      => kizami_option('dwellTime', false) === true,
                            'events'         => Events::config(kizami_option('events', [])),
                            'gaps'           => Gaps::config(kizami_option('gaps', [])),
                        ]);
                    } catch (\Throwable $e) {
                        error_log('Kizami: dashboard not available: ' . $e->getMessage());
                        kizami_log_error($e);
                        $data = ['days' => $days, 'error' => true, 'brand' => Options::brand($kirby)];
                        if ($e instanceof SetupError) {
                            $data['setup'] = $e->getMessage();
                        }
                    }

                    // Under a subdirectory install /k/ lives below the index
                    // URL. A path, not the full URL, like redirectLink(): if the
                    // `url` option differs from the host of the request or says
                    // http://, an absolute link would load cross-origin or as
                    // mixed content, and the browser would not send the login.
                    $data['cssUrl'] = rtrim((string) parse_url($kirby->url('index'), PHP_URL_PATH), '/') . '/k/dashboard.css';

                    $html = (function () use ($data) {
                        ob_start();
                        include __DIR__ . '/views/dashboard.php';
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
                    if (kizami_option('active', false) !== true) {
                        return kizami_not_found();
                    }
                    if ($refused = kizami_dashboard_refused()) {
                        return $refused;
                    }

                    $base = (string) kizami_option('color', '#2f4f2a');
                    $css = (string) file_get_contents(__DIR__ . '/views/dashboard.css');
                    if (preg_match('/^#[0-9a-f]{6}$/i', $base)) {
                        $css .= "\n:root { --base: $base; --up: $base;";
                        foreach (Chart::shades($base) as $i => $s) { $css .= ' --shade-' . ($i + 1) . ": $s;"; }
                        $css .= " }\n";
                    }
                    // Fonts from the configuration (src/Fonts.php) — the usual
                    // reason for extra CSS, now without a file of its own.
                    $fonts = Fonts::css(kizami_option('fonts'));
                    foreach ($fonts['errors'] as $message) { error_log('Kizami: ' . $message); }
                    $css .= $fonts['css'] === '' ? '' : "\n" . $fonts['css'];
                    // Project extras (fine-tuning): a file relative to the project root, loaded last — so it wins,
                    // for example 'site/config/kizami-extra.css'. Fonts are better set via kizami.fonts.
                    $extra = kizami_option('extraCss');
                    if (is_string($extra) && $extra !== '' && !str_contains($extra, '..') && str_ends_with($extra, '.css')) {
                        $file = App::instance()->root('base') . '/' . ltrim($extra, '/');
                        if (is_file($file)) { $css .= "\n" . file_get_contents($file); }
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
         * `active` (checked in kizami_write()). "Without configuration
         * nothing happens" still holds: without `redirects` no route is
         * registered here.
         */
        foreach (kizami_option('redirects', []) as $rawName => $entry) {
            $name = Redirect::cleanName((string) $rawName);
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
                        return kizami_not_found();
                    }

                    // Count, then redirect. kizami_write() never throws — so
                    // the order is harmless. The other way round it wouldn't
                    // be: after a redirect no code runs.
                    kizami_write(fn () => [
                        'type'     => 'redirect',
                        'action'   => 'click',
                        'path'     => Capture::cleanPath(get('from')),
                        'target'   => $name,
                        'position' => is_string(get('position')) && array_key_exists(get('position'), (array) kizami_option('positionNames', [])) ? get('position') : '',
                    ], fn (Store $store, string $session, \DateTimeImmutable $now) => Capture::linkClickCounts(
                        $_SERVER,
                        fn () => $store->hasPageView($session, $now)
                    ));

                    // NOT Response::redirect() — it sends the target through
                    // Url::unIdn(), and Uri::setScheme() only allows
                    // http/https. Verified against Kirby:
                    // Response::redirect('tel:+49…') throws
                    // "InvalidArgumentException: Invalid URL scheme: tel".
                    // Of all things the most important click on the site.
                    // Whoever "tidies this up" breaks it.
                    //
                    // 302, not 301: a permanent redirect would stick in the
                    // browser and future clicks would never be counted again.
                    //
                    // X-Robots-Tag: search engines should neither index nor
                    // follow the redirect; rel="nofollow" on the link itself
                    // is set by the template (or $page->redirectAnchor()).
                    return new Response('', 'text/plain', 302, ['Location' => $target, 'X-Robots-Tag' => 'noindex, nofollow']);
                },
            ];
        }

        return $routes;
    },
]);
unset($kizamiTranslations);

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
function kizami_dashboard_refused(): ?Response
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
function kizami_access_denied(): ?Response
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
    $ip = (string) $kirby->visitor()->ip();
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
            max(1, (int) $kirby->option('auth.trials', 10)),
            max(1, (int) $kirby->option('auth.timeout', 3600))
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
            'Retry-After'   => (string) max(1, $wait),
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
        if (is_array($credentials) && is_string($credentials['user'] ?? null) && $credentials['user'] !== ''
            && is_string($credentials['hash'] ?? null) && password_get_info($credentials['hash'])['algoName'] !== 'unknown') {
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
