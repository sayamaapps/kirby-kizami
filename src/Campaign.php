<?php

namespace Sayamaapps\Kizami;

/**
 * Carries the campaign tag of a visit into links (since 2.1.0).
 *
 * Two kinds of link take the tag:
 *
 * - An order link of a shop in SHOP_HOSTS (digistore24.com/product/…,
 *   checkout-ds24.com/product/…) takes it as `ds24tr`, the vendor's tracking
 *   key. Digistore24 reads `cam` only as the campaign of an affiliate (`aff`),
 *   so an own tag in `cam` never reaches the vendor's purchase data. A link
 *   with `aff` belongs to the affiliate and stays as it is; an existing
 *   `ds24tr` is never replaced; an own `cam` without `aff` is dropped, so an
 *   old affiliate cookie cannot be credited with it.
 * - Any other link of the site's own (one of the given hosts, or a
 *   root-relative path) takes it as the parameter named in
 *   sayamaapps.kizami.campaignParam, unless it already carries a campaign
 *   (`utm_campaign` or that parameter). Other hosts stay untouched.
 *
 * Kirby-free; Runtime::campaignUrl() passes the option and the hosts in.
 * views/campaign.js does the same in the browser for the links of a page.
 */
class Campaign
{
	/** A campaign parameter name the snippet and the script accept. */
	private const PARAM = '/^[A-Za-z0-9_-]{1,32}$/D';

	/** The affiliate's parameters at the shop: never an own tag. */
	private const SHOP_AFFILIATE = 'aff';
	private const SHOP_AFFILIATE_CAMPAIGN = 'cam';

	/** Shops whose /product/ links are order links, also under subdomains (www.). */
	public const SHOP_HOSTS = ['digistore24.com', 'checkout-ds24.com'];

	/** The vendor's tracking key at the shop. */
	public const SHOP_TRACKING = 'ds24tr';

	/**
	 * The link from its parts: the original scheme, host, port and path, the
	 * pairs as written plus the new one, the fragment unless it was consumed.
	 * The new key and value only hold safe characters, so nothing is encoded.
	 *
	 * @param list<array{string,string|null}> $query
	 */
	private static function build(array $parts, array $query, string|null $fragment): string
	{
		$url = '';
		if (isset($parts['host'])) {
			$url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		}
		$url .= $parts['path'] ?? '';
		if ($query !== []) {
			$url .= '?' . self::join($query);
		}
		if ($fragment !== null) {
			$url .= '#' . $fragment;
		}

		return $url;
	}

	/** @param list<array{string,string|null}> $pairs */
	private static function has(array $pairs, string $key): bool
	{
		foreach ($pairs as [$raw]) {
			if (urldecode($raw) === $key) {
				return true;
			}
		}

		return false;
	}

	/** http(s) with a host, or root-relative (/path, not //host and no scheme)? */
	private static function isLink(string $url, array $parts): bool
	{
		if (isset($parts['host'])) {
			return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
		}

		return !isset($parts['scheme']) && str_starts_with($url, '/') && !str_starts_with($url, '//');
	}

	/** Order link of a shop in SHOP_HOSTS? */
	public static function isShop(string $url): bool
	{
		$parts = parse_url($url);

		return is_array($parts) && isset($parts['host']) && self::isShopHost($parts['host']) && str_starts_with($parts['path'] ?? '', '/product/');
	}

	/** One of SHOP_HOSTS or a subdomain of it? */
	public static function isShopHost(string $host): bool
	{
		$host = strtolower($host);
		foreach (self::SHOP_HOSTS as $shop) {
			if ($host === $shop || str_ends_with($host, '.' . $shop)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<array{string,string|null}> $pairs */
	private static function join(array $pairs): string
	{
		return implode('&', array_map(fn (array $pair): string => $pair[1] === null ? $pair[0] : $pair[0] . '=' . $pair[1], $pairs));
	}

	/**
	 * The link with the tag. Unchanged when the tag is empty after cleaning,
	 * the link is neither http(s) nor root-relative, the order link has an
	 * affiliate, or the other link is on another host or already tagged.
	 *
	 * @param $url The link as written in the page or mail.
	 * @param $campaign The tag; cleaned like a utm value (A–Z, a–z, 0–9, `_`, `-`, 50 characters).
	 * @param $param The campaign parameter (sayamaapps.kizami.campaignParam); null: other links stay unchanged.
	 * @param list<string>|null $hosts Hosts that count as the site's own; null: every host.
	 */
	public static function link(string $url, string $campaign, string|null $param = null, array|null $hosts = null): string
	{
		$campaign = Capture::cleanId($campaign);
		$parts = $campaign === '' ? false : parse_url($url);
		if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || !self::isLink($url, $parts)) {
			return $url;
		}
		$host = isset($parts['host']) ? strtolower($parts['host']) : null;
		$query = self::pairs($parts['query'] ?? '');
		$fragment = $parts['fragment'] ?? null;
		if ($host !== null && self::isShopHost($host) && str_starts_with($parts['path'] ?? '', '/product/')) {
			$tagged = self::shopLink($query, $fragment, $campaign);
		} else {
			$tagged = self::ownLink($query, $fragment, $host, $campaign, self::param($param), $hosts);
		}
		if ($tagged === null) {
			return $url;
		}
		[$query, $fragment] = $tagged;

		return self::build($parts, $query, $fragment);
	}

	/**
	 * @param list<array{string,string|null}> $query
	 * @param list<string>|null $hosts
	 * @return array{list<array{string,string|null}>, string|null}|null null: untouched
	 */
	private static function ownLink(array $query, string|null $fragment, string|null $host, string $campaign, string|null $param, array|null $hosts): array|null
	{
		if ($param === null || self::has($query, 'utm_campaign') || self::has($query, $param)) {
			return null;
		}
		if ($host !== null && $hosts !== null && !in_array($host, array_map('strtolower', $hosts), true)) {
			return null;
		}
		$query[] = [$param, $campaign];

		return [$query, $fragment];
	}

	/**
	 * The pairs of a query string or fragment, as written: nothing is re-encoded.
	 *
	 * @return list<array{string,string|null}> raw key, raw value (null without `=`)
	 */
	private static function pairs(string $query): array
	{
		$pairs = [];
		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}
			$at = strpos($pair, '=');
			$pairs[] = $at === false ? [$pair, null] : [substr($pair, 0, $at), substr($pair, $at + 1)];
		}

		return $pairs;
	}

	/** The parameter name from the option; null unless it is a usable string. */
	public static function param(mixed $option): string|null
	{
		return is_string($option) && preg_match(self::PARAM, $option) === 1 ? $option : null;
	}

	/**
	 * @param list<array{string,string|null}> $query
	 * @return array{list<array{string,string|null}>, string|null}|null null: untouched (affiliate link)
	 */
	private static function shopLink(array $query, string|null $fragment, string $campaign): array|null
	{
		$fragmentPairs = $fragment !== null && str_contains($fragment, '=') ? self::pairs($fragment) : [];
		if (self::has($query, self::SHOP_AFFILIATE) || self::has($fragmentPairs, self::SHOP_AFFILIATE)) {
			return null;
		}
		$query = self::without($query, self::SHOP_AFFILIATE_CAMPAIGN);
		if ($fragmentPairs !== []) {
			$fragmentPairs = self::without($fragmentPairs, self::SHOP_AFFILIATE_CAMPAIGN);
			$fragment = $fragmentPairs === [] ? null : self::join($fragmentPairs);
		}
		if (!self::has($query, self::SHOP_TRACKING) && !self::has($fragmentPairs, self::SHOP_TRACKING)) {
			$query[] = [self::SHOP_TRACKING, $campaign];
		}

		return [$query, $fragment];
	}

	/**
	 * @param list<array{string,string|null}> $pairs
	 * @return list<array{string,string|null}>
	 */
	private static function without(array $pairs, string $key): array
	{
		return array_values(array_filter($pairs, fn (array $pair): bool => urldecode($pair[0]) !== $key));
	}
}
