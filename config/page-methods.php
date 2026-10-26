<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\Events;
use Sayamaapps\Kizami\Names;
use Sayamaapps\Kizami\Redirect;

return [
	/**
	 * The URL of a configured redirect, with the current page as origin
	 * — or the fallback if that redirect doesn't exist.
	 *
	 * The fallback is the point: a template writes
	 *
	 *     <a href="<?= $page->redirectLink('call', 'tel:+49…') ?>">
	 *
	 * and keeps a working phone link even if someone removes the config
	 * entry. A link that leads nowhere because a statistics setting
	 * changed would be an own goal — for a business that lives on phone
	 * calls, an expensive one.
	 *
	 * No project terms here: `$name` is whatever the project configures.
	 * The core doesn't know what a phone call is.
	 */
	'redirectLink' => function (string $name, string $fallback = '#', string $position = '') {
		$targets = (array)kizami_option('redirects', []);
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
			. (array_key_exists($position, (array)kizami_option('positionNames', [])) ? '&position=' . rawurlencode($position) : '');
	},
	/**
	 * Complete link with rel="nofollow" — for new templates. Link
	 * followers that respect nofollow then don't trigger a redirect hit
	 * at all. Templates using redirectLink() set rel="nofollow"
	 * themselves.
	 *
	 *     <?= $page->redirectAnchor('call', 'Call us', 'tel:+49…', 'header', ['class' => 'button']) ?>
	 *
	 * $text is escaped; other rel values are kept.
	 */
	'redirectAnchor' => function (string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []) {
		$rel = preg_split('/\s+/', trim((string)($attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
		$rel[] = 'nofollow';
		unset($attributes['href'], $attributes['rel']);
		$attr = \Kirby\Toolkit\Html::attr(['href' => $this->redirectLink($name, $fallback, $position), 'rel' => implode(' ', array_unique($rel))] + $attributes);
		return '<a ' . $attr . '>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
	},
	/**
	 * Counts a configured action (kizami.events) on this page from PHP
	 * (since 1.4.0), see kizami_event(). Returns the page, so a route
	 * can count and answer in one line:
	 *
	 *     'action' => fn () => page('books/kizami')?->kizamiEvent('shortcode_access')
	 *
	 * Never throws and decides nothing: the page is returned whether
	 * the action was counted or not.
	 */
	'kizamiEvent' => function (mixed $key, mixed $detail = null) {
		kizami_event($key, $this, $detail);
		return $this;
	},
];
