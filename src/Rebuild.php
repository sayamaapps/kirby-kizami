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
 * The task is registered from a shutdown function, so it runs after every
 * other one (a session, the site's own). Never throws: the page is already
 * out, a failure only reaches the error log.
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
	 * After a request of a known bot or a retired browser (Runtime::write()):
	 * nobody waits for it. $open opens the Store only after the response, so
	 * the request itself does not touch the database.
	 *
	 * @param callable(): Store $open
	 */
	public static function afterBot(callable $open, \DateTimeImmutable $now): void
	{
		self::schedule($open, $now);
	}

	/**
	 * After a counted visit, with the Store it was written to: schedules the
	 * rebuild when this day's attempt is still open and the visitor will not
	 * wait for it (the response can be ended first) or the last rebuild is
	 * overdue.
	 */
	public static function afterCount(Store $store, \DateTimeImmutable $now): void
	{
		try {
			$state = $store->lastRebuild();
			if ($state['day'] === $now->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d')) {
				return;
			}
			if (self::finisher() === null && !self::overdue($state['done'], $now)) {
				return;
			}
			self::schedule(static fn (): Store => $store, $now);
		} catch (Throwable $e) {
			error_log('Kizami: rebuild check failed: ' . $e->getMessage());
		}
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

	/**
	 * True when the last rebuild that succeeded ($done, UTC, '' = never) is
	 * more than OVERDUE_HOURS before $now.
	 */
	public static function overdue(string $done, \DateTimeImmutable $now): bool
	{
		if ($done === '') {
			return true;
		}
		$last = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $done, new \DateTimeZone('UTC'));
		return $last === false || $now->getTimestamp() - $last->getTimestamp() > self::OVERDUE_HOURS * 3600;
	}

	/**
	 * The task after the response: end it (or at least flush it), then
	 * rebuild. Returns true when this call rebuilt the file.
	 *
	 * @param callable(): Store $open
	 */
	private static function run(callable $open, \DateTimeImmutable $now): bool
	{
		try {
			// The rebuild goes on when the visitor has gone already.
			ignore_user_abort(true);
			$finish = self::finisher();
			if ($finish !== null) {
				$finish();
			} else {
				// The page is complete: send what output buffers still hold.
				while (ob_get_level() > 0 && ((ob_get_status()['flags'] ?? 0) & PHP_OUTPUT_HANDLER_REMOVABLE) !== 0) {
					if (!ob_end_flush()) {
						break;
					}
				}
				flush();
			}
			return $open()->rebuildDaily($now);
		} catch (Throwable $e) {
			error_log('Kizami: rebuild after the response failed: ' . $e->getMessage());
			return false;
		}
	}

	/** @param callable(): Store $open */
	private static function schedule(callable $open, \DateTimeImmutable $now): void
	{
		register_shutdown_function(static function () use ($open, $now): void {
			register_shutdown_function(static fn (): bool => self::run($open, $now));
		});
	}
}
