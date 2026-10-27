<?php

namespace Sayamaapps\Kizami;

/**
 * Short report for scripts (daily digest across sites, chat bot): one Berlin
 * calendar day or one full week Monday–Sunday, always only COMPLETED days.
 * Numbers as fields, plus the finished plain text in `text`.
 *
 * Kirby-free like Analysis; names and settings come in. The text is built
 * here and not by the bot, so no model rephrases numbers and the report is
 * covered by the PHP tests. Route and login: ReportService.php.
 *
 * Comparison: the day with the same weekday one week earlier (a Tuesday
 * against the closing day Monday says nothing), the week with the week
 * before. Absolute numbers instead of percentages — at 30 visits a day a
 * percentage is mostly noise (see Comparison::MIN_BASE).
 */
class Report
{
	/**
	 * Caps for the campaign list. Anyone can create a new tag with one
	 * request (`?cam=<anything>`), so an uncapped list is a lever on the size
	 * of the report and of every chat message built from it. Real campaigns
	 * have more visits than a flood of one-off tags and stay on top.
	 */
	public const CAMPAIGNS_MAX = 20;
	public const CAMPAIGNS_TEXT_MAX = 5;
	public const KINDS = ['day', 'week'];

	/**
	 * @param 'day'|'week' $kind
	 * @param array{
	 *     tiles?: list<string>,
	 *     tileNames?: array<string,string>,
	 *     contactTargets?: list<string>,
	 *     brand?: string,
	 *     dwellTime?: bool,
	 *     events?: array,
	 *     gaps?: array,
	 *     now?: \DateTimeImmutable
	 * } $settings
	 */
	public static function data(Store $store, Names $names, string $kind, \DateTimeImmutable $today, array $settings): array
	{
		$p = self::period($kind, $today);
		$store->setNow($p['now']);
		$days = $p['days'];
		$offset = $p['offset'];

		$to = $p['now'];
		$from = $to->setTime(0, 0, 0)->modify('-' . ($days - 1) . ' days');

		// Tile targets (call, route) always, even with 0 — the other targets
		// only if they were clicked at all.
		$tileNames = (array)($settings['tileNames'] ?? []);
		$clicks = [];
		foreach ((array)($settings['tiles'] ?? []) as $target) {
			$clicks[(string)$target] = 0;
		}
		foreach ($store->actions($days) as $a) {
			$clicks[$a['target']] = $a['count'];
		}
		$actions = [];
		foreach ($clicks as $target => $count) {
			$actions[] = ['name' => (string)($tileNames[$target] ?? $names->target((string)$target)), 'value' => $count];
		}

		// Source per visit (first page of the day), not per page view.
		// contactSources() counts the visits regardless of the targets, but
		// returns nothing without a target — a never-matching target is enough.
		$sources = [];
		$contactTargets = (array)($settings['contactTargets'] ?? []) ?: [''];
		foreach ($store->contactSources($days, $contactTargets)['sources'] as $s) {
			$name = $names->source($s['source']);
			$sources[$name] = ($sources[$name] ?? 0) + $s['visits'];
		}
		$sources = self::descending($sources);

		$pages = [];
		foreach ($store->viewsPerPage($days) as $v) {
			$name = $names->page($v['path']);
			$pages[$name] = ($pages[$name] ?? 0) + $v['count'];
		}
		$pages = self::descending($pages);

		// Campaign keys are machine IDs, not display names, so Names is not
		// involved. Capped (see CAMPAIGNS_MAX); the number left out is reported.
		$campaigns = [];
		foreach ($store->campaigns($days) as $c) {
			$campaigns[] = ['name' => $c['campaign'], 'visits' => $c['visits'], 'pageViews' => $c['pageViews']];
		}

		$data = [
			'kind' => $kind,
			'from' => $from->format('Y-m-d'),
			'to' => $to->format('Y-m-d'),
			'brand' => (string)($settings['brand'] ?? ''),
			'visits' => $store->visits($days),
			'visitsPrevious' => $store->visits($days, $offset),
			'pageViews' => $store->pageViews($days),
			'pageViewsPrevious' => $store->pageViews($days, $offset),
			'clicks' => $store->clicks($days),
			'clicksPrevious' => $store->clicks($days, $offset),
			'directLinkHits' => array_sum(array_column($store->directLinkHits($days), 'count')),
			'discardedLinkHits' => $store->discardedLinkHits($days),
			'discardedLinkHitsPrevious' => $store->discardedLinkHits($days, $offset),
			'actions' => $actions,
			'sources' => array_slice($sources, 0, 4, true),
			'pages' => array_slice($pages, 0, 3, true),
			'campaigns' => array_slice($campaigns, 0, self::CAMPAIGNS_MAX),
			'campaignsOmitted' => max(0, count($campaigns) - self::CAMPAIGNS_MAX),
			'dwellTime' => !empty($settings['dwellTime']) ? self::median($store->dwellTimePerVisit($days)) : null,
			// Reported actions (sayamaapps.kizami.events, since 1.4.0): every configured
			// action with total, visits and its top details. An empty list
			// without configuration. New field, format stays 2.
			'events' => Events::summary($store, (array)($settings['events'] ?? []), $days, I18n::language()),
		];
		// Measurement gaps (sayamaapps.kizami.gaps, since 1.5.0). The period is compared
		// with the period one week earlier (same weekday, or the week before),
		// so both are checked; `gaps` lists every gap that touches either.
		$gaps = (array)($settings['gaps'] ?? []);
		$now = $settings['now'] ?? new \DateTimeImmutable('now');
		$periodStart = $from;
		$periodEnd = $from->modify('+' . $days . ' days');
		$previousStart = $periodStart->modify('-7 days');
		$previousEnd = $periodEnd->modify('-7 days');
		$data['gaps'] = [];
		foreach ($gaps as $gap) {
			$current = Gaps::overlapping([$gap], $periodStart, $periodEnd, $now) !== [];
			$previous = Gaps::overlapping([$gap], $previousStart, $previousEnd, $now) !== [];
			if ($current || $previous) {
				$data['gaps'][] = $gap + ['current' => $current, 'previous' => $previous];
			}
		}
		$data['gap'] = in_array(true, array_column($data['gaps'], 'current'), true);
		$data['gapPrevious'] = in_array(true, array_column($data['gaps'], 'previous'), true);
		if ($kind === 'week') {
			$data['perDay'] = array_map(fn ($d) => ['day' => $d['day'], 'visits' => $d['count'],
				'gap' => Gaps::onDay($gaps, $d['day'], $now) !== []], $store->visitsPerDay($days));
			$d = $store->devices($days);
			$known = $d['mobile'] + $d['tablet'] + $d['desktop'];
			$data['mobileShare'] = $known > 0 ? (int)round(100 * $d['mobile'] / $known) : null;
		}
		$store->setNow(null);
		$data['text'] = self::text($data, $now);
		return $data;
	}

	/** Descending by count, ties by name — stable in the text. */
	private static function descending(array $values): array
	{
		uksort($values, fn ($a, $b) => [$values[$b], (string)$a] <=> [$values[$a], (string)$b]);
		return $values;
	}

	private static function duration(int $seconds): string
	{
		return $seconds < 60
			? I18n::t('duration.seconds', ['s' => $seconds])
			: I18n::t('duration.minutes', ['m' => intdiv($seconds, 60), 's' => $seconds % 60]);
	}

	/** @param list<array{name:string,value:int}>|array<string,int> $entries */
	private static function list(array $entries): string
	{
		$parts = [];
		foreach ($entries as $k => $v) {
			$parts[] = is_array($v) ? $v['name'] . ' ' . $v['value'] : $k . ' ' . $v;
		}
		return implode(' · ', $parts);
	}

	/** null without a measurement — "0 s" would be an invented number. */
	private static function median(array $values): int|null
	{
		if ($values === []) {
			return null;
		}
		sort($values);
		$n = count($values);
		$middle = intdiv($n, 2);
		return (int)round($n % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2);
	}

	/**
	 * Reference time for Store::setNow(): the last microsecond of the report
	 * period. window() rounds a time with a fraction up to the next full
	 * second — the exclusive end then lies exactly on midnight, the last day
	 * is complete.
	 *
	 * @return array{now:\DateTimeImmutable,days:int,offset:int}
	 */
	public static function period(string $kind, \DateTimeImmutable $today): array
	{
		$berlin = new \DateTimeZone('Europe/Berlin');
		$dayStart = $today->setTimezone($berlin)->setTime(0, 0, 0);
		if ($kind === 'week') {
			// Monday of the current week (on a Monday: today) 00:00.
			$weekStart = $dayStart->modify('-' . ((int)$dayStart->format('N') - 1) . ' days');
			return ['now' => $weekStart->modify('-1 microsecond'), 'days' => 7, 'offset' => 1];
		}
		return ['now' => $dayStart->modify('-1 microsecond'), 'days' => 1, 'offset' => 7];
	}

	/**
	 * For an explicitly requested date, the reference day for period(): the
	 * day after (kind "day") or the Monday after the week containing $date
	 * (kind "week"). Without a date: today — so yesterday or the last full
	 * week.
	 *
	 * Rejected is what the report can't answer honestly:
	 * - a day that is not over yet (today, future, current week) — the
	 *   number would be incomplete and still look final;
	 * - a period whose comparison week lies before the retention limit of
	 *   single events (`$retentionMonths`, Store::compact()). After that only
	 *   daily totals exist, the report would silently show zeros.
	 *
	 * @throws \InvalidArgumentException with a message for the caller
	 */
	public static function referenceDay(string $kind, string|null $date, \DateTimeImmutable $today, int $retentionMonths = Store::RETENTION_MONTHS): \DateTimeImmutable
	{
		$berlin = new \DateTimeZone('Europe/Berlin');
		$today = $today->setTimezone($berlin)->setTime(0, 0, 0);
		if ($date === null || $date === '') {
			return $today;
		}
		$day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $berlin);
		if ($day === false || $day->format('Y-m-d') !== $date) {
			throw new \InvalidArgumentException('"date" must be a valid YYYY-MM-DD.');
		}
		$reference = $kind === 'week'
			? $day->modify('-' . ((int)$day->format('N') - 1) . ' days')->modify('+7 days')
			: $day->modify('+1 day');
		if ($reference > $today) {
			throw new \InvalidArgumentException($kind === 'week'
				? 'The week of ' . $date . ' is not over yet.'
				: $date . ' is not over yet.');
		}
		// Earliest comparison day: seven days before the period starts.
		$comparisonStart = $reference->modify($kind === 'week' ? '-14 days' : '-8 days');
		if ($comparisonStart < $today->modify('-' . $retentionMonths . ' months')) {
			throw new \InvalidArgumentException('Single events are kept for ' . $retentionMonths . ' months only; there is no basis for ' . $date . '.');
		}
		return $reference;
	}

	public static function text(array $d, \DateTimeImmutable|null $now = null): string
	{
		$from = new \DateTimeImmutable($d['from']);
		$to = new \DateTimeImmutable($d['to']);
		$week = $d['kind'] === 'week';
		$heading = $week
			? I18n::t('report.week', ['from' => I18n::shortDate($from), 'to' => I18n::shortDate($to)])
			: I18n::weekday((int)$from->format('N')) . ' ' . I18n::shortDate($from);
		$previous = $week ? I18n::t('report.previousWeek') : I18n::t('report.previousWeekday', ['weekday' => I18n::weekday((int)$from->format('N'))]);

		$lines = [trim(($d['brand'] !== '' ? $d['brand'] . ' · ' : '') . $heading)];
		// Gaps first: a dip in the numbers below is then explained before it is read.
		$now ??= new \DateTimeImmutable('now');
		foreach ($d['gaps'] ?? [] as $gap) {
			if (!empty($gap['current'])) {
				$lines[] = Gaps::line($gap, $now);
			}
		}
		if (!empty($d['gapPrevious'])) {
			$lines[] = I18n::t('report.gapPrevious');
		}
		if ($d['visits'] === 0 && $d['pageViews'] === 0) {
			$lines[] = I18n::t('report.noVisits', ['previous' => $previous, 'n' => $d['visitsPrevious']]);
			if ($d['directLinkHits'] > 0) {
				$lines[] = I18n::t('report.directOnly', ['n' => $d['directLinkHits']]);
			}
			return implode("\n", $lines);
		}
		$lines[] = '';
		$lines[] = I18n::t('report.visits', ['n' => $d['visits'], 'previous' => $previous, 'm' => $d['visitsPrevious']]);
		$lines[] = I18n::t('report.pageViews', ['n' => $d['pageViews'], 'm' => $d['pageViewsPrevious']]);
		if ($d['actions'] !== []) {
			$lines[] = I18n::t('report.clicks', ['list' => self::list($d['actions']), 'n' => $d['clicks'], 'm' => $d['clicksPrevious']]);
		}
		if ($d['directLinkHits'] > 0) {
			$lines[] = I18n::t('report.direct', ['n' => $d['directLinkHits']]);
		}
		// Only actions that were reported, so a site without sayamaapps.kizami.events
		// (or a quiet day) gets the same text as before 1.4.0.
		$events = array_filter($d['events'] ?? [], fn ($e) => $e['count'] > 0);
		if ($events !== []) {
			// Psalm sees the event list of the report data as never; the list is built from the rows above.
			/** @psalm-suppress NoValue */
			$lines[] = I18n::t('report.events', ['list' => self::list(array_map(fn ($e) => ['name' => $e['name'], 'value' => $e['count']], $events))]);
		}
		if ($week && !empty($d['perDay'])) {
			$lines[] = I18n::t('report.perDay', ['list' => implode(' · ', array_map(
				fn ($t) => I18n::weekday((int)(new \DateTimeImmutable($t['day']))->format('N')) . ' ' . $t['visits'],
				$d['perDay']
			))]);
		}
		if ($d['sources'] !== []) {
			$lines[] = I18n::t('report.sources', ['list' => self::list($d['sources'])]);
		}
		if ($d['campaigns'] !== []) {
			// The text shows visits for the top campaigns; the JSON field keeps
			// both numbers. The rest is a count, so the line stays one line.
			$shown = array_slice($d['campaigns'], 0, self::CAMPAIGNS_TEXT_MAX);
			$more = count($d['campaigns']) - count($shown) + (int)($d['campaignsOmitted'] ?? 0);
			$list = self::list(array_map(fn ($c) => ['name' => $c['name'], 'value' => $c['visits']], $shown));
			if ($more > 0) {
				$list .= ' · ' . I18n::t('report.campaignsMore', ['n' => $more]);
			}
			$lines[] = I18n::t('report.campaigns', ['list' => $list]);
		}
		if ($d['pages'] !== []) {
			$lines[] = I18n::t('report.pages', ['list' => self::list($d['pages'])]);
		}
		if ($week && $d['mobileShare'] !== null) {
			$lines[] = I18n::t('report.mobileShare', ['n' => $d['mobileShare']]);
		}
		if ($d['dwellTime'] !== null) {
			$lines[] = I18n::t('report.dwellTime', ['time' => self::duration($d['dwellTime'])]);
		}
		return implode("\n", $lines);
	}
}
