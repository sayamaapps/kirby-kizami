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
 */
if (kizami_option('active', false) !== true || Kizami\Events::config(kizami_option('events', [])) === []) {
    return;
}
$kizamiSrc = Kirby\Cms\App::instance()->url('index') . '/k/event.js?v=' . substr((string) md5_file(dirname(__DIR__) . '/views/event.js'), 0, 8);
?>
<script src="<?= htmlspecialchars($kizamiSrc, ENT_QUOTES, 'UTF-8') ?>" defer></script>
