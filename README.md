# Kizami

**Versions:** Kirby 5 · PHP 8.2 to 8.5 · MIT · [Changes](CHANGELOG.md)

Cookieless, server-side visitor statistics for [Kirby CMS](https://getkirby.com).

Kizami counts page views, contact clicks and optional actions on a Kirby site and shows them in a dashboard linked from the Panel. It stores nothing on visitors' devices and needs no tracking script. It is built for small businesses where phone calls, e-mails and route requests matter.

## Features

- Server-side page counting: no tracking script, no cookies, no browser storage.
- Counted redirects for phone, e-mail and map links, with contact rates per source.
- Optional counting of downloads, video plays and other actions.
- Bot filtering; Do Not Track and Global Privacy Control are honoured.
- Dashboard linked from the Panel menu, and a JSON report for scripts.
- English and German; runs on SQLite 3.7.17, so it fits old shared hosting.

## Requirements

- Kirby 5 (`getkirby/cms` 5.0 or newer).
- PHP 8.2, 8.3, 8.4 or 8.5 with the `sqlite3`, `ctype` and `mbstring` extensions.
- A Kirby `storage` root outside the web root. Without it, nothing is counted.

## Installation

Pick one of three ways. The plugin always ends up in `site/plugins/kizami/`.

**Composer** (the installer puts the plugin directly into `site/plugins/kizami/`; `vendor/` is not used for it):

```sh
composer require sayamaapps/kirby-kizami
```

Add `/site/plugins/kizami` to `.gitignore` and let the server run `composer install --no-dev`.

**Git submodule** (no Composer on the server):

```sh
git submodule add https://github.com/sayamaapps/kirby-kizami.git site/plugins/kizami
```

**ZIP.** Download the release archive from GitHub and unpack it to `site/plugins/kizami/`. The archive holds the runtime files only.

A stock Kirby has no `storage` root. Define it in `public/index.php`; `storage/` sits beside `public/`, outside the web root:

```php
<?php
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
echo (new Kirby\Cms\App(['roots' => [
    'index' => __DIR__, 'base' => $base, 'storage' => $base . '/storage',
    'content' => $base . '/content', 'site' => $base . '/site',
]]))->render();
```

Off by default. Minimal `site/config/config.php` (redirects work even while `active` is `false`):

```php
return [
    'sayamaapps.kizami.active' => true,
    'sayamaapps.kizami.redirects' => ['call' => 'tel:+491234567890'],
];
```

## Usage

**Link clicks.** Route links through a counted redirect. The fallback keeps the link working while the redirect is not configured:

```php
<?= $page->kizamiRedirectAnchor('call', 'Call us', 'tel:+491234567890', 'header') ?>
```

The optional fourth argument is a position, recorded only if it is listed in `positionNames`. Safari sends no `Sec-Fetch-User`, so its clicks count only if the same IP address and user agent viewed a page that day.

**Actions.** Declare them in `sayamaapps.kizami.events` (see [Options](#options)), then count them from PHP:

```php
// site/config/config.php
'sayamaapps.kizami.events' => ['download' => ['label' => ['en' => 'Download'], 'detail' => true]],

// route or template, once per request
$page->kizamiEvent('download', 'prices.pdf');
```

A cached page never runs the call, so count in a route or exclude that page from the cache. In the browser, use `data-kizami-event="download"` on links or media, with `<?php snippet('kizami/events') ?>` in the `<head>`.

**Dashboard.** The Panel menu entry **Statistics** opens `/k/dashboard`. HTTPS only. Access:

- any logged-in Panel user, without further setup;
- or a Kirby account with the role `kizami-dashboard` (Basic Auth). Create it in the Panel under Users with that role. The role has no Panel access and no permissions; it appears while `sayamaapps.kizami.active` is on. Other roles can be named in `dashboardRoles`.

Passwords are checked by Kirby itself, so Kirby's `auth.trials` and `auth.timeout` limit failed logins. The dashboard and the report only read: compaction and key rotation run when pages are counted and in `maintenance.php`. There is no password file of the plugin. One known exception: opening the database creates a missing table or migrates an old schema (idempotent).

**JSON report.** Set `'sayamaapps.kizami.report' => true` and give the reading account the role `kizami-report` (see `reportRoles`):

```sh
curl -u 'reader:password' 'https://example.com/k/report?kind=week'
```

`kind=day` (default: yesterday) or `kind=week` (Monday to Sunday), optional `date=YYYY-MM-DD`. HTTPS only.

## Options

Keys are `sayamaapps.kizami.<name>`, flat or nested. `null` means "not set". The 1.x spelling `kizami.<name>` still works when the new key is not set. It is read silently and listed under [Deprecations](#deprecations).

The defaults are not written into the plugin's option block (`config/options.php`), which declares every key with `null`. A value there would count as set and hide the 1.x spelling. The default in the table applies when the key is `null` in both spellings.

| Option (1.x spelling `kizami.<name>`) | Type | Default | Example | Description |
|---|---|---|---|---|
| `active` | bool | `false` | `true` | Master switch for counting, dashboard, report and snapshot. |
| `redirects` | array | `[]` | `['contact' => 'mailto:hello@example.com']` | Counted redirects `name => target` (`tel:`, `mailto:`, `https://` or a path; closures allowed). Route: `/<name>`. |
| `targetNames` | array | `[]` | `['contact' => 'Contact']` | Display names for redirect targets, `name => label`. |
| `sourceNames` | array | `[]` | `['newsletter' => 'Newsletter']` | Display names for referrer hosts and `utm_source` values. |
| `pageNames` | array | `[]` | `['/' => 'Home']` | Display names for paths (default: page title). |
| `contactTargets` | array | `[]` | `['contact']` | Redirects that count as contacts for the contact rate. |
| `dashboardRoles` | array | `['kizami-dashboard']` | `['kizami-dashboard', 'editor']` | Roles of Kirby accounts that may open the dashboard with Basic Auth. Logged-in Panel users need no role. |
| `tiles` | array | first two redirects | `['contact', 'download']` | Redirects shown as dashboard tiles. |
| `tileNames` | array | `[]` | `['contact' => 'Contacts']` | Tile headings, `name => label`. |
| `positionNames` | array | `[]` | `['header' => 'Header']` | Allowed link positions (for example `header`) with labels; others are dropped. |
| `images` | array | `[]` | `['cover' => 'Cover image']` | Image IDs for gallery-open counting (needs the site's own script). |
| `dwellTime` | bool | `false` | `true` | Accept time-on-page beacons at `/k/duration` (needs the site's own script). |
| `events` | array | `[]` | `['download' => ['label' => 'Download', 'detail' => true]]` | Allowed actions `key => ['label' => …, 'detail' => true]`; `detail` allows a short detail. |
| `retentionMonths` | int | `13` | `13` | Months single events and actions are kept (1 to 600). |
| `gaps` | array | `[]` | `[['from' => '2026-01-01 00:00', 'to' => '2026-01-02 00:00', 'reason' => 'Migration']]` | Measurement gaps `['from' => 'Y-m-d H:i', 'to' => …, 'reason' => …]`, marked in dashboard and report. |
| `ownHosts` | array | `[]` | `['shop.example.com']` | Further own domains whose referrers count as internal. |
| `campaignParam` | string\|null | `null` | `'cam'` | Second campaign parameter (for example `cam`), used as `utm_campaign` when that is missing. |
| `language` | string | site language | `'de'` | `en` or `de` for the dashboard and report text. |
| `brand` | string\|closure | site title | `'Example Studio'` | Name in the dashboard title and report (string or closure). |
| `color` | string | `#2f4f2a` | `'#2f4f2a'` | Dashboard accent colour (`#rrggbb`). |
| `fonts` | array\|null | `null` | `['serif' => ['name' => 'Merriweather', 'file' => 'assets/fonts/merriweather.woff2']]` | Dashboard fonts: `['serif' => ['name' => …, 'file' => …], 'sans' => …]`. |
| `extraCss` | string\|null | `null` | `'site/config/kizami-extra.css'` | Extra dashboard CSS file, relative to the project root. |
| `report` | bool | `false` | `true` | Enable `/k/report` (needs `active`) and register the role `kizami-report`. |
| `reportRoles` | array | `['kizami-report']` | `['kizami-report']` | Roles allowed to read the report. |
| `journal` | string | `'wal'` | `'delete'` | SQLite journal mode, `'wal'` or `'delete'` (use `'delete'` where WAL fails). |
| `siteId` | string | site hostname | `'example-site'` | Site identifier in the report; set it to survive a domain change. |
| `reportWithoutHttps` | bool | `false` | `false` | Allow dashboard, report and snapshot over plain HTTP. Local testing only. |
| `snapshot` | bool | `false` | `true` | Enable the backup download `/k/snapshot` (needs `active`); registers the role `kizami-snapshot`. |
| `snapshotRoles` | array | `['kizami-snapshot']` | `['kizami-snapshot']` | Roles allowed to download the snapshot. |

## Deprecations

Kizami renames the public names below. The 1.x names keep working in this release. They are marked `@deprecated` in the code and listed here. Nothing reports them at run time, so debug mode and the error log stay quiet.

| 1.x | Replacement |
|---|---|
| Option `kizami.<name>` | `sayamaapps.kizami.<name>` (same name, same values) |
| Global functions `kizami_*()` | Static methods of `Sayamaapps\Kizami\Runtime`, for example `Runtime::option($app, $key, $default)` |
| Classes `Kizami\<Name>` | `Sayamaapps\Kizami\<Name>`. `SetupError` and `ReportError` keep their names `Kizami\SetupError` and `Kizami\ReportError`. |
| Page methods `$page->redirectLink()`, `$page->redirectAnchor()` | `$page->kizamiRedirectLink()`, `$page->kizamiRedirectAnchor()` |
| Method `Store::setNow()` | `Store::atTime()`, which returns a view and leaves the store unchanged |
| Method `Store::__destruct()` | Nothing to call: it does nothing now. The connection closes when the last `Store` or view is destroyed |

Templates and snippets that call `kizami_option()` or `redirectLink()` keep working. Switch them to the new names at your own pace.

The 1.x public test helpers and the 1.x read methods of `Store` are kept as `@deprecated @internal` for the same reason. They are not part of the stable API.

### Not in the 2.0 dashboard change

The dashboard change (Kirby accounts, no login file, no writes on GET) is in 2.0.0. The following changes also break existing code and are **not** part of that release. They wait for a later decision on 2.0:

- Removal of all names in the table above.
- Moving `SetupError` and `ReportError` into `Sayamaapps\Kizami\`.
- Debug-mode deprecation notices for the 1.x names.
- Errors of the plugin as Kirby exception classes (`Kirby\Exception\…`) instead of `\RuntimeException`, `\InvalidArgumentException` and the plugin's `SetupError` and `ReportError` (which extend `\RuntimeException`). A `catch` in 1.x code would stop matching.
- `extraCss`: a stylesheet reached through a symbolic link that points outside the project root is ignored. 1.x reads it, as does this release.

## Privacy

- **No cookies, no browser storage, no third parties.** Nothing is stored on the visitor's device.
- **Daily rotating salt.** Visits are keyed by a hash of IP address, user agent, date and a secret replaced every day (Europe/Berlin). Raw IP addresses and user agents are never stored; identifiers of different days cannot be linked.
- **Do Not Track and GPC.** Requests with `DNT: 1` or `Sec-GPC: 1` are not recorded.
- **Retention.** Single events are kept 13 months (`retentionMonths`), then reduced to daily totals. Reported actions are deleted after the same period.

See [PRIVACY.md](PRIVACY.md) (English) and [docs/DATENSCHUTZ.md](docs/DATENSCHUTZ.md) (German text for privacy policies). They are not legal advice.

## Maintenance

```sh
php site/plugins/kizami/maintenance.php compact /abs/path/storage/kizami
php site/plugins/kizami/bin/kizami-snapshot /abs/path/storage/kizami /backups/new.sqlite
```

Run `compact` daily if the site may go days without visits; it also removes the previous day's salt key. Add `--retention-months=N` after `maintenance.php` if `sayamaapps.kizami.retentionMonths` is set. Back up with `kizami-snapshot`, not by copying `kizami.sqlite` while the site runs.

Tests: `composer test`. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## Repository layout

The layout follows the Kirby plugin structure with these exceptions:

- `views/` holds the dashboard template, its stylesheet and the action script. Routes and snippets load these files from this path, so they stay at the root.
- `maintenance.php` stays at the root. Site cron jobs call that path.
- `docs/` holds the German privacy text. It is excluded from the archive.

## License

MIT, see [LICENSE](LICENSE).
