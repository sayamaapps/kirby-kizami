<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\Redirect;
use Sayamaapps\Kizami\Runtime;

return [
	/**
	 * The URL of a configured redirect, with the current page as origin
	 * — or the fallback if that redirect doesn't exist.
	 *
	 * The fallback is the point: a template writes
	 *
	 *     <a href="<?= $page->kizamiRedirectLink('call', 'tel:+49…') ?>">
	 *
	 * and keeps a working phone link even if someone removes the config
	 * entry. A link that leads nowhere because a statistics setting
	 * changed would be an own goal — for a business that lives on phone
	 * calls, an expensive one.
	 *
	 * No project terms here: `$name` is whatever the project configures.
	 * The core doesn't know what a phone call is.
	 */
	'kizamiRedirectLink' => function (string $name, string $fallback = '#', string $position = '') {
		$targets = (array)Runtime::option(App::instance(), 'redirects', []);
		$name = Redirect::cleanName($name);

		if ($name === '' || !array_key_exists($name, $targets)) {
			return $fallback;
		}

		// Origin page as an explicit parameter, not via the referrer:
		// that is empty or shortened depending on browser settings.
		//
		// The home page MUST be '/', not '/home'. The route:after hook
		// writes '/' for it (the path is empty there); if this said
		// '/home', the dashboard would show two names for one page.
		$origin = $this->isHomePage() ? '/' : '/' . $this->uri();

		// Under a subdirectory install the route lives below the index
		// URL; `from` stays the page path, so the statistics do not change.
		$base = rtrim((string)parse_url(App::instance()->url('index'), PHP_URL_PATH), '/');

		return $base . '/' . $name . '?from=' . rawurlencode($origin)
			. (array_key_exists($position, (array)Runtime::option(App::instance(), 'positionNames', [])) ? '&position=' . rawurlencode($position) : '');
	},
	/**
	 * Complete link with rel="nofollow" — for new templates. Link
	 * followers that respect nofollow then don't trigger a redirect hit
	 * at all. Templates using kizamiRedirectLink() set rel="nofollow"
	 * themselves.
	 *
	 *     <?= $page->kizamiRedirectAnchor('call', 'Call us', 'tel:+49…', 'header', ['class' => 'button']) ?>
	 *
	 * $text is escaped; other rel values are kept.
	 */
	'kizamiRedirectAnchor' => function (string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []) {
		$rel = preg_split('/\s+/', trim((string)($attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
		$rel[] = 'nofollow';
		unset($attributes['href'], $attributes['rel']);
		$attr = \Kirby\Toolkit\Html::attr(['href' => $this->kizamiRedirectLink($name, $fallback, $position), 'rel' => implode(' ', array_unique($rel))] + $attributes);
		return '<a ' . $attr . '>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
	},
	/**
	 * Deprecated name of kizamiRedirectLink(); kept for sites written for 1.x.
	 */
	'redirectLink' => function (string $name, string $fallback = '#', string $position = '') {
		Kirby\Cms\Helpers::deprecated(
			'Page method redirectLink() is deprecated, use kizamiRedirectLink()',
			'kizami-page-method'
		);

		return $this->kizamiRedirectLink($name, $fallback, $position);
	},
	/**
	 * Deprecated name of kizamiRedirectAnchor(); kept for sites written for 1.x.
	 */
	'redirectAnchor' => function (string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []) {
		Kirby\Cms\Helpers::deprecated(
			'Page method redirectAnchor() is deprecated, use kizamiRedirectAnchor()',
			'kizami-page-method'
		);

		return $this->kizamiRedirectAnchor($name, $text, $fallback, $position, $attributes);
	},
	/**
	 * Counts a configured action (sayamaapps.kizami.events) on this page from PHP
	 * (since 1.4.0), see Runtime::event(App::instance()). Returns the page, so a route
	 * can count and answer in one line:
	 *
	 *     'action' => fn () => page('books/kizami')?->kizamiEvent('shortcode_access')
	 *
	 * Never throws and decides nothing: the page is returned whether
	 * the action was counted or not.
	 */
	'kizamiEvent' => function (mixed $key, mixed $detail = null) {
		Runtime::event(App::instance(), $key, $this, $detail);
		return $this;
	},
];
