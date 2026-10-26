<?php

namespace Kizami;

/**
 * The allow list of `kizami.events` (since 1.4.0): actions the site's own
 * script reports to /k/event, for example a download or a video play.
 *
 *     'kizami.events' => [
 *         'download' => ['label' => ['de' => 'Download', 'en' => 'Download'], 'detail' => true],
 *         'video_play' => ['label' => ['de' => 'Video gestartet', 'en' => 'Video played']],
 *     ]
 *
 * Only listed keys are counted. A detail (file name, platform, album) is
 * kept only for entries with `'detail' => true`; everything else drops it.
 * Kirby-free, like Capture.
 */
final class Events
{
	/** Most details listed per action in the dashboard and the report. */
	public const DETAILS_MAX = 10;

	/** Allowed keys, in the configuration and in the request. */
	public const KEY = '/^[a-z0-9_-]{1,32}\z/'; // \z, not $: $ also matches before a final "\n"

	/**
	 * The usable part of the option: invalid keys and entries that are not
	 * arrays are left out, so a typo counts nothing instead of anything.
	 *
	 * @return array<string, array{label: array<string,string>, detail: bool}>
	 */
	public static function config(mixed $option): array
	{
		$events = [];
		foreach (is_array($option) ? $option : [] as $key => $entry) {
			if (!is_string($key) || !self::isKey($key) || !is_array($entry)) {
				continue;
			}
			$label = $entry['label'] ?? [];
			$label = is_string($label) ? ['en' => $label] : (is_array($label) ? $label : []);
			$events[$key] = [
				'label' => array_filter($label, fn ($text, $language) => is_string($language) && is_string($text) && trim($text) !== '', ARRAY_FILTER_USE_BOTH),
				// Strictly true: a stray 'yes' or 1 does not open the door to free text.
				'detail' => ($entry['detail'] ?? false) === true,
			];
		}
		return $events;
	}

	public static function isKey(mixed $key): bool
	{
		return is_string($key) && preg_match(self::KEY, $key) === 1;
	}

	/** Label in $language, else English, else the first label, else the key. */
	public static function label(array $config, string $key, string $language): string
	{
		$labels = $config[$key]['label'] ?? [];
		return trim((string)($labels[$language] ?? $labels['en'] ?? (reset($labels) ?: $key)));
	}

	/**
	 * Every configured action with its numbers in the window, for the
	 * dashboard and the report alike: most reported first, ties in
	 * configuration order. Configured actions without reports are listed
	 * with 0 — the list is the operator's own and short. Rows of keys no
	 * longer configured are not shown (they stay stored until retention).
	 *
	 * @param array<string, array{label: array<string,string>, detail: bool}> $config from config()
	 * @return list<array{key:string,name:string,count:int,visits:int,detail:bool,details:list<array{name:string,count:int,visits:int}>,detailsOmitted:int}>
	 */
	public static function summary(Store $store, array $config, int $days, string $language, int $offset = 0): array
	{
		$numbers = array_column($store->customEvents($days, $offset), null, 'event');
		$summary = [];
		foreach ($config as $key => $entry) {
			$row = ['key' => $key, 'name' => self::label($config, $key, $language),
				'count' => $numbers[$key]['count'] ?? 0, 'visits' => $numbers[$key]['visits'] ?? 0,
				'detail' => $entry['detail'], 'details' => [], 'detailsOmitted' => 0];
			if ($entry['detail'] && $row['count'] > 0) {
				$details = $store->customEventDetails($key, $days, self::DETAILS_MAX, $offset);
				$row['details'] = array_map(fn ($d) => ['name' => $d['detail'], 'count' => $d['count'], 'visits' => $d['visits']], $details['details']);
				$row['detailsOmitted'] = $details['omitted'];
			}
			$summary[] = $row;
		}
		usort($summary, fn ($a, $b) => $b['count'] <=> $a['count']);
		return $summary;
	}
}
