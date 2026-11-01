<?php

use Sayamaapps\Kizami\Runtime;

/**
 * Script tag for the campaign carry (since 2.1.0):
 *
 *     <?php snippet('kizami/campaign') ?>
 *
 * in the <head> (the script is deferred) or before </body>. Prints nothing
 * while Kizami is off or sayamaapps.kizami.campaignParam is not set, so the
 * template can keep the line. A file from the site's own origin, no inline
 * script: works with "script-src 'self'". The version parameter is a hash of
 * the file, so an update reaches browsers despite the one-day cache.
 * data-kizami-campaign names the parameter the script reads from the address
 * and writes into the site's own links (views/campaign.js).
 */
$kizamiParam = Runtime::campaignParam(\Kirby\Cms\App::instance());
if ($kizamiParam === null || Runtime::option(\Kirby\Cms\App::instance(), 'active', false) !== true) {
	return;
}
$kizamiSrc = Kirby\Cms\App::instance()->url('index') . '/k/campaign.js?v=' . substr((string)md5_file(dirname(__DIR__) . '/views/campaign.js'), 0, 8);
?>
<script src="<?= htmlspecialchars($kizamiSrc, ENT_QUOTES, 'UTF-8') ?>" data-kizami-campaign="<?= htmlspecialchars($kizamiParam, ENT_QUOTES, 'UTF-8') ?>" defer></script>
