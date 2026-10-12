<?php
/**
 * Script tag for the actions (kizami.events, since 1.4.0):
 *
 *     <?php snippet('kizami/events') ?>
 *
 * in the <head> (the script is deferred) or before </body>. Prints nothing
 * while Kizami is off or no action is configured, so the template can keep
 * the line. A file from the site's own origin, no inline script: works with
 * "script-src 'self'". The version parameter is a hash of the file, so an
 * update reaches browsers despite the one-day cache.
 *
 * data-kizami-path names the page being rendered as /k/event finds it again
 * (kizami_page_path()): the address in the browser may be a route of the
 * site's own (a short code like /EEAQ77), a language prefix or a translated
 * slug, which /k/event does not resolve. Missing outside a page (no $page),
 * on the error page and for an ID the beacon would reject — event.js then
 * sends location.pathname as before.
 */
if (kizami_option('active', false) !== true || Kizami\Events::config(kizami_option('events', [])) === []) {
    return;
}
$kizamiSrc = Kirby\Cms\App::instance()->url('index') . '/k/event.js?v=' . substr((string) md5_file(dirname(__DIR__) . '/views/event.js'), 0, 8);
$kizamiPath = isset($page) ? kizami_page_path($page) : null;
?>
<script src="<?= htmlspecialchars($kizamiSrc, ENT_QUOTES, 'UTF-8') ?>"<?php if ($kizamiPath !== null): ?> data-kizami-path="<?= htmlspecialchars($kizamiPath, ENT_QUOTES, 'UTF-8') ?>"<?php endif ?> defer></script>
