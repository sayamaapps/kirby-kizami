<?php

namespace Sayamaapps\Kizami;

use Kirby\Cms\App;
use Kirby\Cms\Helpers;
use Kirby\Cms\Page;
use Kirby\Toolkit\Html;

/**
 * The page methods of the plugin ($page->kizami…()). config/page-methods.php
 * binds them to the page; the 1.x names stay as deprecated aliases.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class PageMethods
{
	/**
	 * Counts a configured action (sayamaapps.kizami.events) on this page from PHP
	 * (since 1.4.0). Returns the page, so a route can count and answer in one
	 * line. Never throws and decides nothing: the page is returned whether the
	 * action was counted or not.
	 */
	public static function event(Page $page, App $kirby, mixed $key, mixed $detail = null): Page
	{
		Runtime::event($kirby, $key, $page, $detail);

		return $page;
	}

	/**
	 * Deprecated 1.x name of redirectAnchor().
	 *
	 * @param array<string, mixed> $attributes
	 */
	public static function legacyRedirectAnchor(Page $page, App $kirby, string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []): string
	{
		Helpers::deprecated(
			'Page method redirectAnchor() is deprecated, use kizamiRedirectAnchor()',
			'kizami-page-method'
		);

		return self::redirectAnchor($page, $kirby, $name, $text, $fallback, $position, $attributes);
	}

	/** Deprecated 1.x name of redirectLink(). */
	public static function legacyRedirectLink(Page $page, App $kirby, string $name, string $fallback = '#', string $position = ''): string
	{
		Helpers::deprecated(
			'Page method redirectLink() is deprecated, use kizamiRedirectLink()',
			'kizami-page-method'
		);

		return self::redirectLink($page, $kirby, $name, $fallback, $position);
	}

	/**
	 * Complete link with rel="nofollow": link followers that respect nofollow
	 * then don't trigger a redirect hit at all. $text is escaped; other rel
	 * values are kept.
	 *
	 * @param array<string, mixed> $attributes
	 */
	public static function redirectAnchor(Page $page, App $kirby, string $name, string $text, string $fallback = '#', string $position = '', array $attributes = []): string
	{
		$rel = preg_split('/\s+/', trim((string)($attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$rel[] = 'nofollow';
		unset($attributes['href'], $attributes['rel']);
		$attr = Html::attr(['href' => self::redirectLink($page, $kirby, $name, $fallback, $position), 'rel' => implode(' ', array_unique($rel))] + $attributes);

		return '<a ' . $attr . '>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
	}

	/**
	 * The URL of a configured redirect, with the current page as origin — or the
	 * fallback if that redirect doesn't exist.
	 *
	 * The fallback is the point: a template keeps a working phone link even if
	 * someone removes the config entry. No project terms here: `$name` is
	 * whatever the project configures.
	 */
	public static function redirectLink(Page $page, App $kirby, string $name, string $fallback = '#', string $position = ''): string
	{
		$targets = (array)Runtime::option($kirby, 'redirects', []);
		$name = Redirect::cleanName($name);
		if ($name === '' || !array_key_exists($name, $targets)) {
			return $fallback;
		}

		// Origin page as an explicit parameter, not via the referrer: that is empty
		// or shortened depending on browser settings. The home page MUST be '/',
		// not '/home': the route:after hook writes '/' for it.
		$origin = $page->isHomePage() ? '/' : '/' . $page->uri();

		// Under a subdirectory install the route lives below the index URL; `from`
		// stays the page path, so the statistics do not change.
		$base = rtrim((string)parse_url($kirby->url('index'), PHP_URL_PATH), '/');

		return $base . '/' . $name . '?from=' . rawurlencode($origin)
			. (array_key_exists($position, (array)Runtime::option($kirby, 'positionNames', [])) ? '&position=' . rawurlencode($position) : '');
	}
}
