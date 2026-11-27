<?php

namespace Sayamaapps\Kizami;

use Throwable;

/**
 * The rebuild of the database file without a cron job (since 3.1.0): what
 * `maintenance.php compact` does (Store::rebuildDaily()), at most once per
 * Berlin day, after the response. It removes leftover bytes of deleted
 * events from the file.
 *
 * VACUUM rewrites the whole file (about 0.13 s for 20 MB measured on a normal
 * disk, longer on a slow one), so no visitor should wait for it:
 *
 * - PHP-FPM and LiteSpeed can end the response before the script ends
 *   (fastcgi_finish_request(), litespeed_finish_request()). There the first
 *   counted visit or bot request of the day rebuilds once the response is out.
 * - Elsewhere (cgi-fcgi, mod_php) PHP cannot end the response early. There a
 *   request of a known bot rebuilds, after its page has been flushed: nobody
 *   waits for a bot. Only when the last rebuild that succeeded is more than
 *   OVERDUE_HOURS old (no bot came, or every attempt failed) does a counted
 *   visit rebuild at its end, so the rebuild also happens on days without bots.
 *
 * VACUUM holds the write lock. A hit that waits for it longer than
 * Store::BUSY_TIMEOUT_MS (5 s) goes to the spool file (Spool) and is written
 * at the next successful write or at the end of the rebuild.
 *
 * The tasks are registered from a shutdown function, so they run after every
 * other one (a session, the site's own). Never throws: the page is already
 * out, a failure only reaches the error log. When the last success is overdue
 * although attempts were made (failing()), the dashboard shows a warning.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Rebuild
{
	/**
	 * Hours since the last rebuild that succeeded after which a counted visit
	 * rebuilds at its end also where the response cannot be ended first. The
	 * privacy text promises "at the latest at the first counted visit after
	 * two days".
	 */
	public const OVERDUE_HOURS = 48;

	/**
	 * After a request of a known bot (Runtime::write()): nobody waits for it.
	 * $open opens the Store only after the response, so the request itself
	 * does not touch the database.
	 *
	 * @param callable(): Store $open
	 */
	public static function afterBot(string $file, callable $open, \DateTimeImmutable $now): void
	{
		self::schedule($file, $open, $now);
	}

	/**
	 * After a counted visit, with the Store it was written to: schedules the
	 * rebuild when this day's attempt is still open and the visitor will not
	 * wait for it (the response can be ended first) or the last rebuild is
	 * overdue.
	 */
	public static function afterCount(Store $store, string $file, \DateTimeImmutable $now): void
	{
		try {
			$state = $store->lastRebuild();
			if ($state['day'] === self::day($now)) {
				return;
			}
			if (self::finisher() === null && !self::overdue($state['done'], $now)) {
				return;
			}
			self::schedule($file, static fn (): Store => $store, $now);
		} catch (Throwable $e) {
			error_log('Kizami: rebuild check failed: ' . $e->getMessage());
		}
	}

	/** The Berlin day of $time. */
	private static function day(\DateTimeImmutable $time): string
	{
		return $time->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
	}

	/**
	 * Ends the response: with the server's finisher, otherwise by flushing
	 * the output buffers, so the page is out before anything slow follows.
	 * The script goes on when the visitor has gone already.
	 */
	private static function endResponse(): void
	{
		ignore_user_abort(true);
		$finish = self::finisher();
		if ($finish !== null) {
			$finish();
			return;
		}
		while (ob_get_level() > 0 && ((ob_get_status()['flags'] ?? 0) & PHP_OUTPUT_HANDLER_REMOVABLE) !== 0) {
			if (!ob_end_flush()) {
				break;
			}
		}
		flush();
	}

	/**
	 * For the dashboard warning: the first attempt since the last rebuild
	 * that succeeded is more than OVERDUE_HOURS old, so the attempts since
	 * have failed for two days. A site without any request has made no
	 * attempt and deleted nothing, so it is not warned about; neither is a
	 * site whose first attempt after an update is still young.
	 *
	 * @param array{day:string,done:string,since:string} $state Store::lastRebuild()
	 */
	public static function failing(array $state, \DateTimeImmutable $now): bool
	{
		return $state['since'] !== '' && self::overdue($state['since'], $now);
	}

	/**
	 * The function that ends the response before the script ends: PHP-FPM's
	 * fastcgi_finish_request() or LiteSpeed's litespeed_finish_request();
	 * null where PHP has none (cgi-fcgi, mod_php, the CLI).
	 */
	public static function finisher(): callable|null
	{
		foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $function) {
			if (function_exists($function)) {
				return $function;
			}
		}
		return null;
	}

	/** Registers $task from a shutdown function, so it runs after every other one (a session, the site's own). */
	private static function last(callable $task): void
	{
		register_shutdown_function(static function () use ($task): void {
			register_shutdown_function($task);
		});
	}

	/**
	 * True when $time (UTC, as kizami_rebuild stores it; '' = never) is more
	 * than OVERDUE_HOURS before $now.
	 */
	public static function overdue(string $time, \DateTimeImmutable $now): bool
	{
		if ($time === '') {
			return true;
		}
		$last = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time, new \DateTimeZone('UTC'));
		return $last === false || $now->getTimestamp() - $last->getTimestamp() > self::OVERDUE_HOURS * 3600;
	}

	/**
	 * The rebuild task: end the response, then rebuild. The file is checked
	 * again here, after the response: a storage directory removed meanwhile is
	 * not created anew. Returns true when this call rebuilt the file.
	 *
	 * @param callable(): Store $open
	 */
	private static function run(string $file, callable $open, \DateTimeImmutable $now): bool
	{
		try {
			self::endResponse();
			// Not from PHP's stat cache: the request itself checked the file.
			clearstatcache(true, $file);
			if (!is_file($file)) {
				return false;
			}
			return $open()->rebuildDaily($now);
		} catch (Throwable $e) {
			error_log('Kizami: rebuild after the response failed: ' . $e->getMessage());
			return false;
		}
	}

	/** @param callable(): Store $open */
	private static function schedule(string $file, callable $open, \DateTimeImmutable $now): void
	{
		self::last(static fn (): bool => self::run($file, $open, $now));
	}
}
