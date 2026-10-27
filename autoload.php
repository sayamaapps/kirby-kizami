<?php

/**
 * Class map for Kirby's F::loadClasses(). Keys are lowercase class names,
 * values are paths relative to the plugin root. tests/AutoloadTest.php checks the
 * map against src/.
 */

return [
	'sayamaapps\\kizami\\access' => 'src/Access.php',
	'sayamaapps\\kizami\\analysis' => 'src/Analysis.php',
	'sayamaapps\\kizami\\capture' => 'src/Capture.php',
	'sayamaapps\\kizami\\chart' => 'src/Chart.php',
	'sayamaapps\\kizami\\comparison' => 'src/Comparison.php',
	'sayamaapps\\kizami\\events' => 'src/Events.php',
	'sayamaapps\\kizami\\fonts' => 'src/Fonts.php',
	'sayamaapps\\kizami\\gaps' => 'src/Gaps.php',
	'sayamaapps\\kizami\\hooks' => 'src/Hooks.php',
	'sayamaapps\\kizami\\i18n' => 'src/I18n.php',
	'sayamaapps\\kizami\\lockout' => 'src/Lockout.php',
	'sayamaapps\\kizami\\migration' => 'src/Migration.php',
	'sayamaapps\\kizami\\names' => 'src/Names.php',
	'sayamaapps\\kizami\\options' => 'src/Options.php',
	'sayamaapps\\kizami\\pagemethods' => 'src/PageMethods.php',
	'sayamaapps\\kizami\\redirect' => 'src/Redirect.php',
	'sayamaapps\\kizami\\report' => 'src/Report.php',
	'sayamaapps\\kizami\\reporterror' => 'src/ReportError.php',
	'sayamaapps\\kizami\\reportservice' => 'src/ReportService.php',
	'sayamaapps\\kizami\\routes' => 'src/Routes.php',
	'sayamaapps\\kizami\\runtime' => 'src/Runtime.php',
	'sayamaapps\\kizami\\secret' => 'src/Secret.php',
	'sayamaapps\\kizami\\setup' => 'src/Setup.php',
	'sayamaapps\\kizami\\setuperror' => 'src/SetupError.php',
	'sayamaapps\\kizami\\snapshotresponse' => 'src/SnapshotResponse.php',
	'sayamaapps\\kizami\\snapshotservice' => 'src/SnapshotService.php',
	'sayamaapps\\kizami\\store' => 'src/Store.php',
];
