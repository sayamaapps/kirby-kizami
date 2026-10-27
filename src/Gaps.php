<?php

namespace Sayamaapps\Kizami;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Measurement gaps (`sayamaapps.kizami.gaps`, since 1.5.0): periods in which counting
 * was interrupted, for example an outage. The days they touch get a marker
 * in the dashboard and a flag in the report, so a dip is explained and an
 * automated comparison can skip it.
 *
 *     'sayamaapps.kizami.gaps' => [
 *         ['from' => '2026-10-13 00:21', 'to' => '2026-10-13 15:40', 'reason' => 'Server outage'],
 *         ['from' => '2026-10-20 08:00', 'reason' => 'Migration, still running'], // no 'to': open until now
 *     ]
 *
 * Times are site time (Europe/Berlin, like the day identifiers), `from` is
 * inclusive, `to` exclusive. Invalid entries are logged and ignored, never
 * fatal. Kirby-free, like Events.
 */
class Gaps
{
	public const FORMAT = 'Y-m-d H:i';
	public const REASON_MAX = 200;
	public const ZONE = 'Europe/Berlin';

	/**
	 * The usable gaps, sorted by start. Each one is
	 * `['from' => 'Y-m-d H:i', 'to' => 'Y-m-d H:i'|null, 'reason' => string]`.
	 *
	 * @return list<array{from:string,to:?string,reason:string}>
	 */
	public static function config(mixed $option): array
	{
		if ($option === null) {
			return [];
		}
		if (!is_array($option) || !array_is_list($option)) {
			self::invalid('sayamaapps.kizami.gaps must be a list of gaps; all of them are ignored');
			return [];
		}
		$gaps = [];
		foreach ($option as $index => $entry) {
			$gap = self::entry($entry);
			if (is_string($gap)) {
				self::invalid("sayamaapps.kizami.gaps[$index] ignored: $gap");
				continue;
			}
			$gaps[] = $gap;
		}
		usort($gaps, fn ($a, $b) => strcmp($a['from'], $b['from']));
		return $gaps;
	}

	/** @return array{from:string,to:?string,reason:string}|string the gap, or why it is invalid */
	private static function entry(mixed $entry): array|string
	{
		if (!is_array($entry)) {
			return 'not an array';
		}
		$from = self::moment($entry['from'] ?? null);
		if ($from === null) {
			return "'from' must be 'Y-m-d H:i' (site time)";
		}
		$to = $entry['to'] ?? null;
		if ($to !== null) {
			$until = self::moment($to);
			if ($until === null) {
				return "'to' must be 'Y-m-d H:i' (site time), or left out for an open gap";
			}
			if ($until <= $from) {
				return "'to' must be later than 'from'";
			}
		}
		$reason = self::reason($entry['reason'] ?? '');
		if ($reason === null) {
			return "'reason' must be plain text";
		}
		return ['from' => $from, 'to' => $to, 'reason' => $reason];
	}

	private static function invalid(string $message): void
	{
		error_log('Kizami: ' . $message);
	}

	/** One sentence for the report text or a chart tooltip, with the reason if there is one. */
	public static function line(array $gap, DateTimeImmutable $now): string
	{
		$period = self::period($gap, $now);
		return $gap['reason'] === ''
			? I18n::t('gap.line', ['period' => $period])
			: I18n::t('gap.lineReason', ['period' => $period, 'reason' => $gap['reason']]);
	}

	/** The canonical 'Y-m-d H:i' string, or null (also for times that do not exist, such as 02:30 on a DST change day). */
	private static function moment(mixed $value): string|null
	{
		if (!is_string($value)) {
			return null;
		}
		$time = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new DateTimeZone(self::ZONE));
		return $time !== false && $time->format(self::FORMAT) === $value ? $value : null;
	}

	/** The gaps that touch one site-time day, given as 'Y-m-d'. */
	public static function onDay(array $gaps, string $day, DateTimeImmutable $now): array
	{
		$start = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone(self::ZONE));
		if ($start === false) {
			return [];
		}
		return self::overlapping($gaps, $start, $start->modify('+1 day'), $now);
	}

	/**
	 * The gaps that touch the half-open interval [$start, $end).
	 *
	 * @return list<array{from:string,to:?string,reason:string}>
	 */
	public static function overlapping(array $gaps, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): array
	{
		$hits = [];
		foreach ($gaps as $gap) {
			[$from, $to] = self::span($gap, $now);
			if ($from < $end && $to > $start) {
				$hits[] = $gap;
			}
		}
		return $hits;
	}

	/** "13.10.2026 00:21–15:40", or "since 13.10.2026 00:21" while still open. */
	public static function period(array $gap, DateTimeImmutable $now): string
	{
		[$from, $to] = self::span($gap, $now);
		$start = I18n::date($from) . ' ' . $from->format('H:i');
		if ($gap['to'] === null) {
			return I18n::t('gap.since', ['from' => $start]);
		}
		$end = $from->format('Y-m-d') === $to->format('Y-m-d')
			? $to->format('H:i')
			: I18n::date($to) . ' ' . $to->format('H:i');
		return I18n::t('gap.span', ['from' => $start, 'to' => $end]);
	}

	/**
	 * Plain text: control characters and line breaks become spaces, runs of
	 * spaces collapse, at most REASON_MAX characters. Angle brackets stay;
	 * the dashboard escapes them on output. Null for invalid UTF-8 or a
	 * non-string.
	 */
	private static function reason(mixed $value): string|null
	{
		if ($value === null) {
			return '';
		}
		if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
			return null;
		}
		$text = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '';
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
		if (mb_strlen($text) > self::REASON_MAX) {
			$text = rtrim(mb_substr($text, 0, self::REASON_MAX));
		}
		return $text;
	}

	/**
	 * Start (inclusive) and end (exclusive) of a gap. An open gap ends now.
	 *
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
	 */
	public static function span(array $gap, DateTimeImmutable $now): array
	{
		$zone = new DateTimeZone(self::ZONE);
		$start = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $gap['from'], $zone);
		$end = $gap['to'] === null
			? $now->setTimezone($zone)
			: DateTimeImmutable::createFromFormat('!' . self::FORMAT, $gap['to'], $zone);
		return [$start, $end];
	}
}
