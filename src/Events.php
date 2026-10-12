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
    /** Allowed keys, in the configuration and in the request. */
    public const KEY = '/^[a-z0-9_-]{1,32}\z/'; // \z, not $: $ also matches before a final "\n"

    /** Most details listed per action in the dashboard and the report. */
    public const DETAILS_MAX = 10;

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
        return trim((string) ($labels[$language] ?? $labels['en'] ?? (reset($labels) ?: $key)));
    }
}
