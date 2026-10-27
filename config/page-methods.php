<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\PageMethods;

// The page methods are in src/PageMethods.php. Kirby binds each closure to the page.
return [
	'kizamiRedirectLink' => function (string $name, string $fallback = '#', string $position = '') {
		return PageMethods::redirectLink($this, App::instance(), $name, $fallback, $position);
	},
	'kizamiRedirectAnchor' => function (string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []) {
		return PageMethods::redirectAnchor($this, App::instance(), $name, $text, $fallback, $position, $attributes);
	},
	'redirectLink' => function (string $name, string $fallback = '#', string $position = '') {
		return PageMethods::legacyRedirectLink($this, App::instance(), $name, $fallback, $position);
	},
	'redirectAnchor' => function (string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []) {
		return PageMethods::legacyRedirectAnchor($this, App::instance(), $name, $text, $fallback, $position, $attributes);
	},
	'kizamiEvent' => function (mixed $key, mixed $detail = null) {
		return PageMethods::event($this, App::instance(), $key, $detail);
	},
];
