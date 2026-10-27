<?php

namespace Sayamaapps\Kizami;

use DateTimeImmutable;

/**
 * The computing half of the capture, deliberately without Kirby: the routes
 * in config/routes.php pass in the Kirby request values and the secret, so
 * the tests check all of this without a running Kirby.
 */
class Capture
{
	/**
	 * Known bot signatures. Deliberately short: a complete list is
	 * impossible, this one covers the loudest crawlers.
	 */
	private const BOTS = [
		'bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit',
		'headless', 'python-requests', 'curl', 'wget', 'ahrefs', 'semrush',
	];

	/** Characters kept of an action detail (cleanDetail()). */
	public const DETAIL_MAX = 100;

	/**
	 * Detail of a reported action (file name, platform, album title), at
	 * most 100 characters. Free text from the browser, so:
	 *  - not a string or not valid UTF-8 → empty (discarded, not repaired);
	 *  - control characters and line breaks become spaces, invisible format
	 *    characters (bidi overrides, zero-width) are removed, `<` and `>`
	 *    too — the detail reaches the dashboard and the report text;
	 *  - a URL keeps only host and path: query and fragment can carry
	 *    tokens, e-mail addresses or session IDs. That applies to
	 *    `scheme://…`, `//…` and `www.…` anywhere in the text, and to a word
	 *    starting with `/` (a site path). `mailto:`, `tel:` and `sms:` keep
	 *    only the scheme — the address or number is personal data.
	 */
	public static function cleanDetail(mixed $value): string
	{
		if (!is_string($value) || preg_match('//u', $value) !== 1) {
			return '';
		}
		$value = preg_replace(['/\p{Cf}+/u', '/[\p{Cc}\p{Zl}\p{Zp}]+/u', '/[<>]/'], ['', ' ', ''], $value);
		$value = preg_replace_callback(
			// Each URL runs to the next space, minus trailing punctuation
			// such as the `)` of "(https://…)".
			'~\b(mailto|tel|sms):\S*|(?:(?:\b[a-z][a-z0-9+.\-]*:)?//|\bwww\.|(?<!\S)/)\S*?(?=[)\]},.;:!]*(?:\s|$))~iu',
			fn (array $m) => self::withoutQuery($m[0], $m[1] ?? ''),
			$value
		);
		$value = trim(preg_replace('/\s+/u', ' ', $value));
		preg_match('/^.{0,' . self::DETAIL_MAX . '}/us', $value, $cut);
		return rtrim($cut[0] ?? '');
	}

	/** utm/ID value: harmless characters only, capped. */
	public static function cleanId(mixed $value): string
	{
		if (!is_string($value)) {
			return '';
		}
		return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $value), 0, 50);
	}

	/**
	 * Path without query string, without HTML-dangerous characters, capped.
	 * Cleaning is also the XSS protection at the source: what doesn't get in
	 * here can't do anything in the dashboard.
	 *
	 * Only valid UTF-8 is kept (since 1.6.1): a path with invalid bytes would
	 * break the JSON of the report, so it is discarded (empty), not repaired.
	 * The cap is 255 bytes, cut on a character boundary.
	 */
	public static function cleanPath(mixed $path): string
	{
		if (!is_string($path)) {
			return '';
		}
		$path = explode('?', $path, 2)[0]; // before the check: the query is dropped anyway
		if (preg_match('//u', $path) !== 1) {
			return '';
		}
		$path = str_replace(['<', '>', '"', "'"], '', $path);
		$path = substr($path, 0, 255);
		// substr() may have cut a multibyte character in half: drop the stub (at most 3 bytes).
		while ($path !== '' && preg_match('//u', $path) !== 1) {
			$path = substr($path, 0, -1);
		}
		return $path;
	}

	/**
	 * Referrer reduced to the bare referring domain (no full URL).
	 *
	 * The host comes straight from a request header, so (since 1.6.1):
	 *  - not valid UTF-8 → empty (discarded, not repaired): an invalid byte
	 *    in a stored host made json_encode() fail, and /k/report answered
	 *    with an empty body;
	 *  - control and format characters (zero-width, bidi overrides) and
	 *    `<>"'` are removed — the host reaches the dashboard and the report;
	 *  - at most 100 characters, cut between characters.
	 */
	public static function cleanReferrer(mixed $referrer, array $ownHosts = []): string
	{
		if (!is_string($referrer) || $referrer === '') {
			return '';
		}
		$host = parse_url($referrer, PHP_URL_HOST);
		if (!is_string($host) || $host === '' || preg_match('//u', $host) !== 1) {
			return '';
		}
		$host = strtolower(rtrim(preg_replace('/[\p{Cc}\p{Cf}<>"\']+/u', '', $host), '.'));
		if ($host === '') {
			return '';
		}
		foreach ($ownHosts as $own) {
			if (is_string($own) && $host === strtolower(rtrim($own, '.'))) {
				return '(internal)'; // kept apart from external and unknown sources
			}
		}
		preg_match('/^.{0,100}/us', $host, $cut);
		return $cut[0] ?? '';
	}

	/**
	 * Rough device class from the user agent. Only three values, blurry on
	 * purpose: the question is "do people read the menu on their phone?",
	 * not device models. The user agent itself is not stored.
	 */
	public static function deviceClass(string $ua): string
	{
		$ua = strtolower($ua);
		if ($ua === '') {
			return 'desktop';
		}
		if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet') ||
			(str_contains($ua, 'android') && !str_contains($ua, 'mobile'))) {
			return 'tablet';
		}
		if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
			return 'mobile';
		}
		return 'desktop';
	}

	/** An empty user agent counts as a bot — no real browser omits it. */
	public static function isBot(string $ua): bool
	{
		$ua = strtolower($ua);
		if ($ua === '') {
			return true;
		}
		foreach (self::BOTS as $bot) {
			if (str_contains($ua, $bot)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does this redirect hit count as a human click? (since Kizami 1.0.0)
	 *
	 * Reason: many contact-redirect hits had no page view with the same day
	 * identifier. They came from a link follower with a browser-like user
	 * agent and rotating IP addresses that isBot() does not recognise.
	 *
	 * Rules:
	 *  1. `Sec-Fetch-User: ?1` (the browser confirms a user action) → counts.
	 *  2. Otherwise the hit only counts if the same day identifier has
	 *     already viewed a page today ($hasPageView, only evaluated here).
	 *
	 * Deliberately NOT "Sec-Fetch-* present but no Sec-Fetch-User → discard":
	 * Safari/WebKit sends Sec-Fetch-Site/-Mode/-Dest but no Sec-Fetch-User
	 * (WebKit bug 247697, open since 2022). That rule would discard every
	 * click from an iPhone. Chromium and Firefox omit the header entirely
	 * without a user action, so "missing" looks the same in every engine and
	 * decides nothing.
	 *
	 * Known undercount: a visitor without Sec-Fetch-User (Safari, older
	 * browsers, some in-app browsers) whose IP address or user agent changes
	 * between page view and click is not counted.
	 *
	 * Strict variant (also discards every Safari click without a page-view
	 * match): return false here when the request has a Sec-Fetch-Mode header.
	 */
	public static function countsLinkClick(bool $userActivated, callable $hasPageView): bool
	{
		if ($userActivated === true) {
			return true;
		}
		return $hasPageView() === true;
	}

	/**
	 * The 1.x signature: takes the server array instead of the flag. Kept for
	 * callers of the class alias Kizami\Capture; the redirect route uses
	 * countsLinkClick().
	 *
	 * @param array<string, mixed> $server
	 */
	public static function linkClickCounts(array $server, callable $hasPageView): bool
	{
		return self::countsLinkClick(($server['HTTP_SEC_FETCH_USER'] ?? null) === '?1', $hasPageView);
	}

	/**
	 * Honours Do Not Track and Global Privacy Control. Both are an explicit
	 * objection to being recorded; we comply.
	 */
	public static function optedOut(array $server): bool
	{
		return ($server['HTTP_DNT'] ?? '') === '1' ||
			($server['HTTP_SEC_GPC'] ?? '') === '1';
	}

	/**
	 * Too much data for a beacon? (since 1.4.0) Only /k/event takes free
	 * text, so it caps the request: a declared body over $limit bytes, a
	 * Content-Length that is no number, or a query string over $limit.
	 */
	public static function requestTooLarge(array $server, int $limit): bool
	{
		$length = $server['CONTENT_LENGTH'] ?? '';
		if ($length !== '' && (!is_string($length) || !ctype_digit($length) || (int)$length > $limit)) {
			return true;
		}
		return strlen((string)($server['QUERY_STRING'] ?? '')) > $limit;
	}

	/**
	 * Pseudonymous day identifier: SHA256(IP + date + UA + secret). Rotates
	 * daily. No promise of full anonymity. Without a secret there is NO hash —
	 * the caller then records nothing (no fallback value that could be brute
	 * forced).
	 */
	public static function sessionHash(string $ip, string $ua, string $secret, DateTimeImmutable $day): string
	{
		if ($secret === '') {
			return '';
		}
		return hash('sha256', $ip . $day->format('Y-m-d') . $ua . $secret);
	}

	/** One URL from cleanDetail(): host and path only, or just the scheme. */
	private static function withoutQuery(string $url, string $scheme): string
	{
		if ($scheme !== '') {
			return strtolower($scheme) . ':';
		}
		if ($url[0] === '/' && ($url[1] ?? '') !== '/') {
			return preg_replace('/[?#].*$/s', '', $url);
		}
		$parts = parse_url(stripos($url, 'www.') === 0 ? '//' . $url : $url);
		if (!is_array($parts) || !isset($parts['host'])) {
			return '';
		}
		// parse_url() has split off query and fragment; user, password and
		// port are dropped as well.
		return strtolower($parts['host']) . ($parts['path'] ?? '');
	}
}
