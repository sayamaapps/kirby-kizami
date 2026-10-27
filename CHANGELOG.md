# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to the counting definition** (what counts as a visit, page view
  or click) is a **major** version and carries a date, so a break in a time
  series can be explained later.
- A schema change is a **minor** version and ships with an automatic migration.
- A change to the privacy text (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the plugin must then review its privacy
  policy.
- The report JSON carries `format`; it increases only when a field is removed,
  renamed or changes meaning.

## [1.9.0] — 2026-10-27

Structure and naming, as a 1.x minor release: counting, output, storage, schema
and the 1.x API are unchanged, nothing here breaks existing code. The new names
are the ones to use; the 1.x names keep working silently and are listed under
Deprecations in the README. Removals and other breaking changes are planned for
2.0 (README, Planned for 2.0).

### Changed

- Options are read as `sayamaapps.kizami.*`. `kizami.*` remains read as fallback,
  without a deprecation notice.
- Global `kizami_*()` functions are wrappers around `Sayamaapps\Kizami\Runtime`.
  Marked `@deprecated`; no notice at run time.
- Classes live in `Sayamaapps\Kizami\`; the 1.x names `Kizami\*` are class aliases.
  `SetupError` and `ReportError` keep their 1.x names (`Kizami\SetupError`,
  `Kizami\ReportError`), so `get_class()` and error logs show the same names as
  in 1.x. `get_class()` and Reflection report the new names of the other classes;
  `catch`, `instanceof` and type declarations accept both.
- Dashboard login reads `PHP_AUTH_USER` and `PHP_AUTH_PW` first, as in 1.x. The
  `Authorization` header is a fallback for FastCGI setups.
- Page methods are prefixed `kizami…()`. `redirectLink()` and `redirectAnchor()`
  remain as deprecated names.
- The 1.x methods of the plugin's classes stay callable and return what they
  returned in 1.x, also without a Kirby app where 1.x did not need one.

### Fixed

- Own referrer hosts are bound as SQL parameters instead of inline literals.
- The retention error message names the option spelling in use (`kizami.` or
  `sayamaapps.kizami.`).
- The report reference time is a view on the store (`Store::atTime()`), not a
  setter that stayed set. `Store::setNow()` remains as a deprecated method.

## [1.8.2] — 2026-10-26

### Fixed

- **A data directory that links into the web root is refused.** The storage
  root check only resolved the root itself, so `storage/kizami` (or the
  pre-1.0 `kennzahlen`) as a symlink into the web root was accepted and the
  database and the daily key were written there. Both directories are now
  resolved and checked before anything is written; the error names the
  directory. A normal setup is unaffected. No schema change.
- **The test suite passes in the week of a DST change.** The previous-period
  check added seven UTC days to a Berlin time, one hour off across the change.
  It now adds Berlin calendar days and also checks fixed reference times on
  both sides of the 2026 and 2027 changes.

## [1.8.1] — 2026-10-24

### Fixed

- **Kirby 6: the report endpoint no longer fails.** `/k/report` passed `null` as
  the third argument of `Response::json()`; Kirby 6 types it as `bool` and threw a
  `TypeError`. Now `false`, identical behaviour on Kirby 5 (the body is already a
  string). The test suite runs on Kirby 5 and on the Kirby 6 pre-release.

## [1.8.0] — 2026-10-20

### Changed

- **Single events and reported actions are kept 13 months instead of five
  years** **[privacy text]**: the new option `kizami.retentionMonths` sets the
  period (a whole number from 1 to 600, default 13; anything else is logged and
  13 is used). Single events older than the period are rolled up into daily
  totals as before, and the daily totals (views, clicks, devices) are kept
  forever. Reported actions older than the period are deleted, not rolled up.
  `DATENSCHUTZ.md` and `PRIVACY.md` state 13 months and the option.
- **Irreversible on the first maintenance run after the update** (the first
  counted request of the day, the dashboard, `/k/report` or `maintenance.php
  compact`): single events older than 13 months are rolled up, and their details
  (pages, sources, devices, link targets, dwell time) are gone for good. Actions
  older than 13 months are deleted for good. A site that wants the old period
  must set `kizami.retentionMonths` to 60 before updating, and its cron job for
  `maintenance.php` must pass `--retention-months=60`.
- `maintenance.php` accepts `--retention-months=N` (same validation as the
  option), because the job has no Kirby options of its own.
- The report's `date` parameter refuses a comparison day or week before the
  limit of single events; the error names the retention in months (it said
  "five years"). The report JSON is unchanged. No schema change.

## [1.7.1] — 2026-10-17

### Fixed

- **A stale daily key is now also removed on a filtered visit, and the
  maintenance job no longer creates one** **[privacy text]**: since 1.7.0 the
  key rotated outside counted visits only through the authenticated
  dashboard, `/k/report` and `/k/snapshot` and through `maintenance.php`, so a
  site that only gets filtered visitors (Do Not Track, Global Privacy
  Control, bots) and whose operator opens none of these kept yesterday's key.
  `maintenance.php` runs as the cron user: the `secret.txt` it created
  belonged to that user, the web server could not read it and made its own at
  the next visit. New `Secret::expire()` removes a stale key and never
  creates one (a missing or current file is untouched). `kizami_write()`
  calls it before it returns for a filtered request, and `maintenance.php`
  uses it instead of `rotate()`: it reports `stale daily key removed`,
  `daily key current` or `no daily key` (instead of `stale daily key
  replaced`) and exits 1 only when a stale key cannot be removed. Dashboard,
  report and snapshot still replace the key after authentication. No schema
  change.

## [1.7.0] — 2026-10-17

### Fixed

- **Deleted events no longer stay behind in the database file and in new
  snapshots** **[privacy text]**: `compact()` removed events older than five
  years with a plain `DELETE`, and SQLite keeps the bytes of deleted rows in
  the file (and the backup API copies them into every snapshot). Every
  connection now runs with `PRAGMA secure_delete = ON`. `compact()`, and so
  the dashboard and `maintenance.php compact`, also rebuilds the file with
  `VACUUM` once per database file (marked in the header field `user_version`),
  after every roll-up that deleted events (the mark is cleared, also by the
  roll-up in a visitor's request) and whenever free pages exist, because
  SQLite does not rewrite a page that becomes free and old builds (3.7.17)
  leave parts of a deleted row in a page that stays in use. `/k/report` now runs `compact()` like the dashboard (after
  authentication), so a site that only fetches the report still gets this
  maintenance; if the rebuild fails, the failure is logged and the report and
  dashboard are still delivered. The rebuild needs up to twice the file size
  in free disk space for a while and blocks writers while it runs (5 s busy
  timeout, after that page views are lost): on a large file run
  `maintenance.php compact` first, so the first rebuild does not happen in a
  dashboard call. The deleted contents are no longer contained in the database
  file; journal and WAL files are deleted, not zeroed. The visitor's request never runs `VACUUM`: leftovers from the
  daily roll-up in a visitor's request stay until the next of these runs. `Store::snapshot()`
  rebuilds the copy with `VACUUM` before `integrity_check`, so a snapshot
  carries no free pages whatever the state of the source; the checksum of
  `/k/snapshot` is computed on that file. No schema change.
- **The daily key now also rotates without a counted visit**
  **[privacy text]**: `Secret::get()` replaced the key only on a counted
  visit, so a quiet site, a switched-off module or only filtered visitors left
  the last day's key on disk indefinitely. New `Secret::rotate()` replaces a
  stale key (a missing file stays missing, a current key is untouched); the
  dashboard, `/k/report` and `/k/snapshot` (after authentication, never for
  anonymous requests) and `maintenance.php` call it. The job reports
  `stale daily key replaced` or `daily key current`, and exits 1 when a stale
  key cannot be replaced. No schema change.
- **The dashboard's login no longer works over plain HTTP**: `/k/dashboard`
  and `/k/dashboard.css` checked the Basic Auth login whatever the protocol,
  so a reusable password could travel unencrypted, and the 401 made the
  browser ask for it. Both routes now answer `403` `HTTPS only.` (no
  `WWW-Authenticate`, `Cache-Control: no-store`) before any challenge or
  check, the Panel cookie included, with the same rule and the same option as
  `/k/report` and `/k/snapshot`: `kizami.reportWithoutHttps`, now documented
  for the dashboard too. Behind a reverse proxy Kirby must see HTTPS
  (`X-Forwarded-Proto`, `X-Forwarded-SSL` or the `url` option). A site with
  the module off still answers Kirby's real 404. No schema change.
- **Failed dashboard logins are now limited**: the file login
  (`site/config/kizami.php`, or `kizami.previewAccess`) ran `password_verify()`
  for every request, with no limit on wrong passwords. New `Kizami\Lockout`
  counts failed logins per client address and per user name in
  `storage/kizami/logins.json` (hashes only, no address or name in plain text)
  and uses Kirby's own limits, `auth.trials` (10) and `auth.timeout` (3600
  seconds). Once reached, the login answers `429` with `Retry-After` and no
  challenge, without running `password_verify()`; a request without
  credentials never counts, a successful login does not reset the counters.
  If the file cannot be read, the login answers `503` pointing to the error
  log (no challenge, no password check); a failure only while counting a
  wrong password is logged and answered with the usual `401`. The address is `REMOTE_ADDR`: behind a reverse proxy all visitors
  share one address unless Kirby sees the real one. The Panel-cookie login
  is not affected. No schema change.
- **A storage root inside the web root, or none at all, is now refused with
  a clear message**: Kizami wrote `kizami.sqlite` and `secret.txt` wherever
  `roots.storage` pointed, so a root below the document root let a web server
  without a deny rule serve both as plain files. And Kirby defines no
  `storage` root by itself: without one the call died in a `TypeError`, the
  page rendered, nothing was counted and the dashboard only said "not
  available". New `Kizami\Setup::storageRoot()` compares the real paths of the
  `storage` and `index` roots (symlinks resolved; a storage root that does not
  exist yet is judged by its nearest existing parent) before anything is
  read or written. If the storage root is missing, equal to or inside the
  index root, pages still render and redirects still redirect, nothing is
  written and the error log gets the reason. A Panel user sees it in the
  dashboard under the warning; the file login (no storage, so no lockout
  counters) is denied without checking the password and answers 503 pointing to
  the error log, no challenge and no path. **Operators: a storage root inside the web root is no longer
  supported; move it outside** (the README shows an `index.php` with
  `storage` as a sibling of `public/`). On upgrade, data already below the web
  root stays where it is and nothing is counted until the root lies outside.
  No schema change.
- **Contact links and the dashboard stylesheet work under a subdirectory
  install**: with the index URL `https://example.invalid/subsite`,
  `redirectLink()` and `redirectAnchor()` returned `/call?from=%2F`, the
  domain root, and the dashboard linked `/k/dashboard.css`. Both now start
  with the path of Kirby's index URL (`/subsite/call?from=%2F`,
  `/subsite/k/dashboard.css`), as a path and not a full URL, so a `url`
  option that differs from the request host or says `http://` cannot make the
  stylesheet load cross-origin or as mixed content; a root
  install produces the same bytes as before. The `from` value stays the page
  path (`/`, `/contact`), so the statistics paths do not change. No schema
  change.

## [1.6.1] — 2026-10-17

### Fixed

- **`/k/report` never answers 200 without JSON**: one request with an
  invalid UTF-8 byte in the `Referer` header was enough to store a host that
  made `json_encode()` fail, and the route then answered 200 with an empty
  body. The route now encodes the body itself: invalid bytes in rows stored
  earlier appear as U+FFFD, and anything else that cannot be encoded is
  logged and answered with a 500 and `{"ok":false,"error":"Internal error."}`.
  The report JSON `format` stays 2; valid data gives the same bytes as before.
- **Referrer and path only as valid UTF-8**: a referrer host with invalid
  UTF-8 is no longer stored; control and format characters and `<>"'` are
  removed from it, and the 100-character cap no longer cuts a multibyte
  character in half. A path, such as the `from` parameter of a redirect, is
  discarded if it is not valid UTF-8, and the 255-byte cap cuts between
  characters. Valid hosts and paths are stored exactly as before; no schema
  change.
- **Dashboard with earlier rows**: source, page and missing-page names that
  hold invalid UTF-8 from before this version show U+FFFD instead of an
  empty cell.
- **`/k/snapshot` streams the copy**: the route read the whole database copy
  into a string, so a database larger than PHP's `memory_limit` ended the
  request in a fatal error and left the full copy in `storage/kizami/`. The
  copy is now sent in chunks, the memory use no longer depends on the size of
  the database, and the file is unlinked as soon as it is open. Leftover
  `.snapshot-*` files of aborted requests are removed after one hour. The
  bytes, `X-Kizami-Sha256` and the other headers are unchanged, plus a
  `Content-Length`; no schema change.

## [1.6.0] — 2026-10-17

### Changed

- **Own site as a source**: a referrer that is one of the site's own hosts
  (the index URL and `kizami.ownHosts`, with or without `www.`) now counts as
  internal in the dashboard and the report as well, not only at capture.
  Rows recorded before a host was listed, or through a preview host, no
  longer form a source of their own: among all page views they are
  "Internal navigation", as the entry of a visit "Entry not recorded".
- **"Unknown" is now "Entry not recorded"** ("Unbekannt" → "Einstieg nicht
  erfasst"): the row for visits whose first recorded page was opened from an
  own page (a visit across midnight, after a 404 page, from the preview) was
  hard to tell from "Direct / unknown". The hint of "Which sources lead to
  contact" explains the row.
- **Contact clicks once per visit and target**: the "Contact clicks" column
  of "Which sources lead to contact" counts each target once per visit, so
  one visit clicking the phone number 41 times no longer dominates the
  column. Visits, "Visits with contact", the share, the tiles and the report
  are unchanged; the report JSON `format` stays 2.

## [1.5.0] — 2026-10-14

### Added

- **Measurement gaps**: `kizami.gaps` records periods in which counting was
  interrupted, for example an outage, so a dip is explained and an automated
  comparison can skip it. Off by default.
  - **`kizami.gaps`**: a list of `['from' => 'Y-m-d H:i', 'to' => 'Y-m-d H:i',
    'reason' => '…']` in site time (Europe/Berlin). `to` may be left out for an
    open gap that runs until now. `from` is included, `to` is not. Entries with
    a date that does not exist, a `to` that is not after `from`, a reason that
    is not text, or a value that is not a list are logged and ignored. The
    reason is plain text, at most 200 characters, escaped in the dashboard.
  - **Dashboard**: every shown day a gap touches gets a band in both trend
    charts, and a note under them lists the gaps of the shown range.
  - **`/k/report`**: `gaps` (the gaps touching the period or its comparison,
    with `current` and `previous`), `gap` and `gapPrevious` (booleans) and
    `gap` on each `perDay` entry of a week. The text gets a `Measurement gap …`
    line, and one more when the comparison was affected.
  - Gaps change no number: they only mark. The JSON `format` stays 2, as only
    fields were added.

## [1.4.0] — 2026-10-13

### Added

- **Actions**: counted clicks and plays the page reports, for example
  downloads, video and audio plays, clicks on social profiles or a
  newsletter window that opened. Off by default.
  - **`kizami.events`**: the allow list, `key => ['label' => ['de' => …,
    'en' => …], 'detail' => true|false]`. Keys `[a-z0-9_-]{1,32}`; other keys
    and entries that are not arrays are ignored. Only listed keys count.
  - **`POST /k/event`** (`event`, `path`, optional `detail`): the rules of
    `/k/image` — own `Origin` and `Sec-Fetch-Site`, existing pages only, no
    bots, no empty user agent, no DNT/GPC, always `204`, Kirby's real 404
    while off. Requests with more than 2 KB of data are discarded. The
    detail is kept only for actions with `'detail' => true`: at most 100
    characters, control characters and line breaks become spaces, `<` and
    `>` are removed, URLs keep host and path (no query, no fragment, no
    credentials, no port), `mailto:`/`tel:`/`sms:` keep only the scheme.
  - **`GET /k/event.js`** and the snippet **`kizami/events`**: the browser
    half as a file from the site's own server, no inline script (works with
    `script-src 'self'`). `data-kizami-event` / `data-kizami-detail` on links
    and buttons count clicks (including middle clicks), on `<audio>`/`<video>`
    the start of playback. `window.kizami.event(key, detail)` reports from the
    site's own code. Each action and detail at most once per page view;
    `sendBeacon`, falling back to `fetch` with `keepalive`. No cookies, no
    browser storage.
  - **`data-kizami-path`** on the script tag of the snippet: the path of the
    page being rendered (`/` for the home page, otherwise `/` and the page
    ID), which `event.js` reports instead of `location.pathname` (still the
    fallback without the attribute). `/k/event` only accepts existing pages,
    and the address in the browser is not always one: a page served by a
    route of the site's own (a short code like `/EEAQ77`), under a language
    prefix or with a translated slug. Without the attribute, every action on
    such a page is answered with `204` but not stored. The server check is
    unchanged: still only existing pages.
  - **`$page->kizamiEvent($key, $detail = null)`**: counts a configured
    action from PHP, for a route or a template — for example a short-code
    route counting `shortcode_access` without JavaScript. Returns the page.
    The rules of `/k/event` without `Origin`/`Sec-Fetch-Site`: only listed
    keys (others count nothing, silently), the detail cleaned and only for
    `'detail' => true`, no bots, no empty user agent, no DNT/GPC, the day
    identifier of the visit, the page path as above, nothing on the error
    page and for `HEAD` requests. Never throws; errors go to the error log.
  - **Dashboard section "Actions"** ("Aktionen"): total and visits with the
    action per configured action, and the ten most frequent details for
    actions with `detail`.
  - **Report field `events`**: `[{"key", "name", "count", "visits", "detail",
    "details": [{"name", "count", "visits"}], "detailsOmitted"}]` per
    configured action, empty without `kizami.events`. The `text` gets an
    `Actions: …` / `Aktionen: …` line after page views and link clicks when
    anything was reported. `format` stays 2: no field was removed, renamed
    or changed in meaning.
- `Store::customEvents()`, `Store::customEventDetails()` and
  `Kizami\Events` behind the dashboard and the report.
- README: a headless browser (`HeadlessChrome`) counts as a bot; browser
  tests that should be counted must set a normal user agent.

### Changed — schema (automatic)

- New table `kizami_custom_events` (`created_at`, `event`, `detail`, `path`,
  `session`), created on the next request. `kizami_events` is not touched:
  adding a type to its `CHECK` constraint would have meant copying the whole
  table on every site on the first request after the update. Actions follow
  the five-year retention of single events, are not rolled up into daily
  totals, and are part of every snapshot (CLI and `/k/snapshot`). They change
  neither visits, page views, clicks nor the action rate.

### [privacy text]

- `DATENSCHUTZ.md` / `PRIVACY.md` describe the optional actions: what is
  stored (kind, page, time, day identifier, a cleaned short detail only where
  allowed), for how long (five years, not rolled up), and that the script
  uses no cookies and no browser storage. Nothing new is stored unless
  `kizami.events` is set; sites that set it must review their privacy policy.
  They also say that the web server itself can count selected actions when
  a page is called, for example through a short link (`kizamiEvent()`).

## [1.3.0] — 2026-10-12

### Added

- **`kizami.campaignParam`**: a second campaign parameter, for example `cam`.
  Its value is stored as `utm_campaign` when the link has no `utm_campaign`
  (`utm_campaign` wins). Same cleaning as `utm_*` values: `A–Z`, `a–z`, `0–9`,
  `_`, `-`, at most 50 characters. Not set by default; a value that is not a
  non-empty string is ignored. No schema change, no migration.
- **Report fields `campaigns` and `campaignsOmitted`**: `[{"name", "visits",
  "pageViews"}]` per campaign tag of the period, sorted by visits, at most 20
  (`campaignsOmitted` counts the rest — any request can create a tag, so the
  list is capped). `name` is the raw key, not a display name. `format` stays
  2: new fields.
- **Report text**: a `Campaigns: …` / `Kampagnen: …` line after the sources
  with the top 5 and `+n more` / `+n weitere`, only if there are campaigns.
- `Store::campaigns()`, the store method behind the report field.

### [privacy text]

- `DATENSCHUTZ.md` / `PRIVACY.md` mention the optional further campaign
  parameter. Nothing new is stored unless `kizami.campaignParam` is set; sites
  that set it must review their privacy policy.

## [1.2.0] — 2026-10-11

### Added
- Option `journal`: `wal` (default) or `delete`. `delete` runs the database
  with SQLite's classic rollback journal — one file, no `-wal`/`-shm`, no shared
  memory. For shared hosters where WAL is unreliable (SQLite 3.7.17 builds,
  earlier "disk I/O error"s), WAL can leave the database malformed. Switching
  an existing database is automatic on the next request.
- Openers without Kirby options (`maintenance.php`, `bin/kizami-snapshot`) keep
  the mode the file already has, instead of switching it back to WAL.

### Changed
- `Store` closes its connection when it is destroyed.

## [1.1.0] — 2026-10-08

### Added

- **Panel menu entry** "Statistics" / "Kennzahlen" (chart icon) that opens
  `/k/dashboard` in a new tab, for every Panel role, while `kizami.active` is
  true. A plain link area, nothing is added to the Panel bundle. Sites with
  their own `panel.menu` list add `'kizami'` to it and can drop a hand-made
  dashboard entry.

## [1.0.0] — 2026-10-08

First release as a Composer package. Before 1.0.0 the code lived as a copy
named `kennzahlen` (`site/plugins/kennzahlen/`), with German
names throughout. The changes below are relative to that copy.

### Changed — counting definition (2026-10-08)

- **Redirect clicks are filtered.** A hit on a counted redirect is recorded
  only if the browser sends `Sec-Fetch-User: ?1`, or if the same day identifier
  already has a page view on the same (Europe/Berlin) day. Everything else is
  still redirected (302), but is not stored as an event. It only increases a
  daily counter (`kizami_discarded`: date + count). Reason: many
  contact-redirect hits had no page view at all. They came from a
  link-following crawler with a browser-like user agent and rotating IPs.
  Expect lower click numbers and contact rates from the switch-over day on.
  Known undercount: browsers without `Sec-Fetch-User` (Safari/WebKit, older
  browsers, some in-app browsers) whose IP or user agent changes between page
  view and click. Earlier data is not filtered retroactively.

### Changed — everything renamed to English (breaking)

Code, options, routes, page methods, roles, report fields, files and tables
are English now. There are no fallbacks for the old names: a site switching
from the copy updates its config and templates once, using the tables below.

| Area | Before (`kennzahlen` copy) | Now (Kizami) |
|---|---|---|
| Options | `kennzahlen.*` | `kizami.*` |
| | `aktiv`, `weiterleitungen` | `active`, `redirects` |
| | `zielnamen`, `quellnamen`, `seitennamen` | `targetNames`, `sourceNames`, `pageNames` |
| | `kontaktziele`, `kacheln`, `kachelnamen` | `contactTargets`, `tiles`, `tileNames` |
| | `positionsnamen`, `bilder`, `verweildauer` | `positionNames`, `images`, `dwellTime` |
| | `eigeneHosts`, `farbe`, `schriften` (`datei`) | `ownHosts`, `color`, `fonts` (`file`) |
| | `zusatzCss` | `extraCss` |
| | `bericht`, `berichtRollen`, `kennung`, `berichtOhneHttps` | `report`, `reportRoles`, `siteId`, `reportWithoutHttps` |
| | `sicherung`, `sicherungRollen` | `snapshot`, `snapshotRoles` |
| | `farbe` fell back to the site option `themenfarbe` | no fallback; set `color` |
| | brand from the site field `marke`, else the title | `brand` option (string or closure), else the title |
| | preview detected via `site/config/credentials.php` | `previewAccess` closure (see README) |
| Access file | `site/config/kennzahlen.php` with `benutzer`, `hash` | `site/config/kizami.php` with `user`, `hash` |
| Routes | `/k/bericht?art=tag\|woche&datum=` | `/k/report?kind=day\|week&date=` |
| | `/k/sicherung` | `/k/snapshot` |
| | `/k/tafel.css`, `/k/dashboard?tage=` | `/k/dashboard.css`, `/k/dashboard?days=` |
| | `/k/bild` (`bild`, `von`) | `/k/image` (`image`, `path`) |
| | `/k/dauer` (`von`, `sekunden`) | `/k/duration` (`path`, `seconds`) |
| | redirect links `/<name>?von=…` | `/<name>?from=…` |
| Page methods | `weiterleitungsLink()`, `weiterleitungsAnker()` | `redirectLink()`, `redirectAnchor()` |
| Positions (examples) | `kopf`, `fuss`, `inhalt` | `header`, `footer`, `content` |
| Panel roles | `kennzahlen-bericht`, `kizami-sicherung` | `kizami-report`, `kizami-snapshot` |
| Helpers | `kennzahlen_*()`, `kizami_verzeichnis()`, … | `kizami_option()`, `kizami_directory()`, `kizami_log_error()`, … |
| Classes | `Kennzahlen\*` aliases, German class names | `Kizami\*` only: `Capture`, `Store`, `Access`, `Redirect`, `Report`, `ReportService`, `SnapshotService`, `Analysis`, `Chart`, `Secret`, `Names`, `Options`, `Fonts`, `Comparison`, `I18n`, `Migration` |
| CLI | `wartung.php bereinigen\|sichern` | `maintenance.php compact\|snapshot` |
| Dashboard CSS | German class names and custom properties | English (`--base`, `--shade-1`…, `.tile`, …); check any `extraCss` |

**Report JSON, `format` 2.** Query `kind=day|week` and `date=YYYY-MM-DD`.
Fields:

| `format` 1 | `format` 2 |
|---|---|
| `art`, `von`, `bis`, `marke` | `kind`, `from`, `to`, `brand` |
| `besuche`, `besucheVorher` | `visits`, `visitsPrevious` |
| `aufrufe`, `aufrufeVorher` | `pageViews`, `pageViewsPrevious` |
| `klicks`, `klicksVorher` | `clicks`, `clicksPrevious` |
| `direkteLinkaufrufe` | `directLinkHits` |
| `verworfeneLinkaufrufe(Vorher)` | `discardedLinkHits(Previous)` |
| `aktionen` `[{name, wert}]` | `actions` `[{name, value}]` |
| `herkunft`, `seiten` | `sources`, `pages` |
| `verweildauer` | `dwellTime` |
| `jeTag` `[{tag, anzahl}]` (week) | `perDay` `[{day, visits}]` (week) |
| `handyAnteil` (week) | `mobileShare` (week) |
| `fehler` | `error` |
| — | `generatedAt` (new) |

`ok`, `format`, `site` and `text` keep their names. `text` follows the site
language (see below); German sites get the same wording as before.

### Added

- Package `sayamaapps/kirby-kizami`, Kirby plugin ID `sayamaapps/kizami`,
  installed to `site/plugins/kizami/`, PHP namespace `Kizami\`.
- **English and German.** Dashboard and report text default to English and
  switch to German via `kizami.language`, the site's default language, or
  Kirby's `locale` option. The strings are also registered as Kirby
  translations (`kizami.*`).
- If an old copy is still present in `site/plugins/kennzahlen/`, the package
  stays inactive and logs that, instead of counting twice.
- Report fields `discardedLinkHits` and `discardedLinkHitsPrevious`. The
  dashboard also shows the discarded count.
- `GET /k/snapshot`: a consistent SQLite snapshot over HTTPS for backup jobs
  on hosts without a shell (`kizami.snapshot`, `kizami.snapshotRoles`). Its
  own Panel role `kizami-snapshot` (no Panel access), separate from the
  report role, because the snapshot contains raw events. The snapshot is one
  self-contained file (rollback journal, no `-wal`/`-shm`).
- `bin/kizami-snapshot`: the same snapshot from the command line, without
  merging old events first.
- `kizami.fonts`: dashboard fonts from configuration instead of a
  site-specific CSS file.
- `$page->redirectAnchor()`: a complete link with `rel="nofollow"`.
  Redirect responses send `X-Robots-Tag: noindex, nofollow`.
- CI on PHP 8.2–8.4, each against a real SQLite 3.7.17 build and a current
  SQLite.

### Fixed

- On SQLite 3.7.17, opening a read-only, schema-less database threw a
  `SQLite3Exception` instead of the documented `RuntimeException` (found by
  the new 3.7.17 CI job). Callers caught it anyway, so pages were unaffected.

### Unchanged (data continuity)

- **No data is lost.** On the first request after the switch, Kizami moves
  `storage/kennzahlen/` to `storage/kizami/` (`kennzahlen.sqlite` →
  `kizami.sqlite` with its `-wal`/`-shm`, `geheimnis.txt` → `secret.txt`,
  `fehler.log` → `errors.log`) and migrates the tables in one transaction:
  `ereignisse` → `kizami_events` (columns and values in English, ids kept),
  `kennzahlen_tage` → `kizami_days`, `kennzahlen_verworfen` →
  `kizami_discarded`, `kennzahlen_wartung` → `kizami_maintenance`. Stored
  link positions `kopf`/`fuss`/`inhalt` become `header`/`footer`/`content`;
  other positions and all redirect names stay as they are. The secret keeps
  its content, so day identifiers continue across the switch. Works on
  SQLite 3.7.17 (no `RENAME COLUMN` needed); covered by
  `tests/MigrationTest.php` in CI on both SQLite versions. Backup jobs that
  read `storage/kennzahlen/` directly must switch to `storage/kizami/`.
- The counting itself (visits, page views, clicks, retention) is unchanged
  apart from the redirect-click filter above.

### [privacy text]

- `DATENSCHUTZ.md` / `PRIVACY.md` now describe the redirect-click filter and
  the daily count of discarded hits, and mention the snapshot route. The
  operations notes use the new option, route and file names.
