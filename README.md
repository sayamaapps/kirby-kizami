# Kizami

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

- PHP 8.2–8.4 with the `sqlite3` extension, and Kirby 5.
- A Kirby `storage` root outside the web root. Without it, nothing is counted.

## Installation

```sh
composer require sayamaapps/kirby-kizami
```

Until the package is on Packagist, add the repository to `composer.json` first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/sayamaapps/kirby-kizami" }]
```

Composer installs to `site/plugins/kizami/`. Add that path to `.gitignore` and deploy it like `vendor/`.

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
    'kizami.active' => true,
    'kizami.redirects' => ['call' => 'tel:+491234567890'],
];
```

## Usage

**Link clicks.** Route links through a counted redirect. The fallback keeps the link working while the redirect is not configured:

```php
<?= $page->redirectAnchor('call', 'Call us', 'tel:+491234567890', 'header') ?>
```

The optional fourth argument is a position, recorded only if it is listed in `positionNames`. Safari sends no `Sec-Fetch-User`, so its clicks count only if the same IP address and user agent viewed a page that day.

**Actions.** Declare them in `kizami.events` (see [Options](#options)), then count them from PHP:

```php
// site/config/config.php
'kizami.events' => ['download' => ['label' => ['en' => 'Download'], 'detail' => true]],

// route or template, once per request
$page->kizamiEvent('download', 'prices.pdf');
```

A cached page never runs the call, so count in a route or exclude that page from the cache. In the browser, use `data-kizami-event="download"` on links or media, with `<?php snippet('kizami/events') ?>` in the `<head>`.

**Dashboard.** The Panel menu entry **Statistics** opens `/k/dashboard`. It is HTTPS only, and failed file logins are limited by Kirby's `auth.trials` and `auth.timeout`. Access: any logged-in Panel user, or the login in `site/config/kizami.php` (keep it out of git):

```php
<?php return ['user' => 'client', 'hash' => '$2y$10$…']; // password_hash('…', PASSWORD_DEFAULT)
```

**JSON report.** Set `'kizami.report' => true` and give the reading account the role `kizami-report` (see `reportRoles`):

```sh
curl -u 'reader:password' 'https://example.com/k/report?kind=week'
```

`kind=day` (default: yesterday) or `kind=week` (Monday to Sunday), optional `date=YYYY-MM-DD`. HTTPS only.

## Options

Keys are `kizami.<name>`, flat or nested. `null` means "not set".

| Option | Default | Description |
|---|---|---|
| `active` | `false` | Master switch for counting, dashboard, report and snapshot. |
| `redirects` | `[]` | Counted redirects `name => target` (`tel:`, `mailto:`, `https://` or a path; closures allowed). Route: `/<name>`. |
| `targetNames` | `[]` | Display names for redirect targets, `name => label`. |
| `sourceNames` | `[]` | Display names for referrer hosts and `utm_source` values. |
| `pageNames` | `[]` | Display names for paths (default: page title). |
| `contactTargets` | `[]` | Redirects that count as contacts for the contact rate. |
| `tiles` | first two redirects | Redirects shown as dashboard tiles. |
| `tileNames` | `[]` | Tile headings, `name => label`. |
| `positionNames` | `[]` | Allowed link positions (for example `header`) with labels; others are dropped. |
| `images` | `[]` | Image IDs for gallery-open counting (needs the site's own script). |
| `dwellTime` | `false` | Accept time-on-page beacons at `/k/duration` (needs the site's own script). |
| `events` | `[]` | Allowed actions `key => ['label' => …, 'detail' => true]`; `detail` allows a short detail. |
| `retentionMonths` | `13` | Months single events and actions are kept (1 to 600). |
| `gaps` | `[]` | Measurement gaps `['from' => 'Y-m-d H:i', 'to' => …, 'reason' => …]`, marked in dashboard and report. |
| `ownHosts` | `[]` | Further own domains whose referrers count as internal. |
| `campaignParam` | `null` | Second campaign parameter (for example `cam`), used as `utm_campaign` when that is missing. |
| `language` | site language | `en` or `de` for the dashboard and report text. |
| `brand` | site title | Name in the dashboard title and report (string or closure). |
| `color` | `#2f4f2a` | Dashboard accent colour (`#rrggbb`). |
| `fonts` | `null` | Dashboard fonts: `['serif' => ['name' => …, 'file' => …], 'sans' => …]`. |
| `extraCss` | `null` | Extra dashboard CSS file, relative to the project root. |
| `previewAccess` | `null` | Closure returning `['user' => …, 'hash' => …]` while a build password protects the site; `null` once live. |
| `report` | `false` | Enable `/k/report` (needs `active`) and register the role `kizami-report`. |
| `reportRoles` | `['kizami-report']` | Roles allowed to read the report. |
| `journal` | `'wal'` | SQLite journal mode, `'wal'` or `'delete'` (use `'delete'` where WAL fails). |
| `siteId` | site hostname | Site identifier in the report; set it to survive a domain change. |
| `reportWithoutHttps` | `false` | Allow dashboard, report and snapshot over plain HTTP. Local testing only. |
| `snapshot` | `false` | Enable the backup download `/k/snapshot` (needs `active`); registers the role `kizami-snapshot`. |
| `snapshotRoles` | `['kizami-snapshot']` | Roles allowed to download the snapshot. |

## Privacy

- **No cookies, no browser storage, no third parties.** Nothing is stored on the visitor's device.
- **Daily rotating salt.** Visits are keyed by a hash of IP address, user agent, date and a secret replaced every day (Europe/Berlin). Raw IP addresses and user agents are never stored; identifiers of different days cannot be linked.
- **Do Not Track and GPC.** Requests with `DNT: 1` or `Sec-GPC: 1` are not recorded.
- **Retention.** Single events are kept 13 months (`retentionMonths`), then reduced to daily totals. Reported actions are deleted after the same period.

See [PRIVACY.md](PRIVACY.md) (English) and [DATENSCHUTZ.md](DATENSCHUTZ.md) (German text for privacy policies). They are not legal advice.

## Maintenance

```sh
php site/plugins/kizami/maintenance.php compact /abs/path/storage/kizami
php site/plugins/kizami/bin/kizami-snapshot /abs/path/storage/kizami /backups/new.sqlite
```

Run `compact` daily if the site may go days without visits; it also removes the previous day's salt key. Add `--retention-months=N` after `maintenance.php` if `kizami.retentionMonths` is set. Back up with `kizami-snapshot`, not by copying `kizami.sqlite` while the site runs.

Tests: `composer test`. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE).
