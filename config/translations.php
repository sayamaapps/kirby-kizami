<?php

use Sayamaapps\Kizami\I18n;

// The dashboard and report strings, also usable as t('kizami.<key>').
$translations = [];
foreach (I18n::LANGUAGES as $language) {
	foreach (I18n::table($language) as $key => $text) {
		$translations[$language]['kizami.' . $key] = $text;
	}
}

return $translations;
