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
    prefix or with a translated slug. Found on a site with short-code
    routes, where every action was answered with `204` but not stored. The
    server check is unchanged: still only existing pages.
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
  memory. For shared hosters where WAL is unreliable: on 2026-10-11 a site on
  a shared host (SQLite 3.7.17, earlier "disk I/O error"s) had its database malformed
  in WAL mode. Switching an existing database is automatic on the next request.
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
named `kennzahlen` in each site (`site/plugins/kennzahlen/`), with German
names throughout. The changes below are measured against that copy.

### Changed — counting definition (2026-10-08)

- **Redirect clicks are filtered.** A hit on a counted redirect is recorded
  only if the browser sends `Sec-Fetch-User: ?1`, or if the same day identifier
  already has a page view on the same (Europe/Berlin) day. Everything else is
  still redirected (302), but is not stored as an event. It only increases a
  daily counter (`kizami_discarded`: date + count). Reason: on one site,
  117 of 169 contact-redirect hits had no page view at all. They came from a
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
