<?php

namespace Sayamaapps\Kizami;

use RuntimeException;
use Throwable;

/**
 * The spool file (since 3.1.0): hits that could not be written because the
 * database stayed locked longer than the busy timeout (Store::BUSY_TIMEOUT_MS,
 * for example during a rebuild, see Rebuild). Instead of being lost, such a
 * hit is appended here, one JSON line each, and Store::readSpool() writes it
 * into the database at the next successful write and at the end of a rebuild.
 *
 * The file sits next to the database (storage/kizami/spool.jsonl, outside the
 * web root like the database) and holds exactly the fields the database would
 * get, nothing more. Lines older than the retention period are dropped when
 * the file is read. Appending takes an exclusive flock(); reading takes the
 * database's write lock first and then the same flock(), so a parallel reader
 * finds the file already emptied and nothing is counted twice.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Spool
{
	public const FILE = 'spool.jsonl';

	/**
	 * A hit for later. $check: 'pageview' for a link click without a confirmed
	 * user action, which only counts if the same day identifier has viewed a
	 * page that day (decided when the file is read, see LinkFilter). Returns
	 * false when the file cannot be written.
	 *
	 * @param array<string,string|int> $row the row as Store::write() takes it
	 */
	public static function add(string $dbPath, array $row, string $check = ''): bool
	{
		$file = self::path($dbPath);
		$new = !file_exists($file);
		if (is_dir($file) || !is_writable($new ? dirname($file) : $file)) {
			return false;
		}
		$handle = fopen($file, 'a');
		if ($handle === false) {
			return false;
		}
		try {
			if ($new) {
				chmod($file, 0600);
			}
			$line = json_encode(['row' => $row] + ($check !== '' ? ['check' => $check] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			return is_string($line) && flock($handle, LOCK_EX) && fwrite($handle, $line . "\n") === strlen($line) + 1 && fflush($handle);
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * Writes the hit of Runtime::write() in the request, within one budget
	 * of Store::BUSY_TIMEOUT_MS (5 s) for all statements together: first what
	 * waits in the spool file (Store::readSpool()), then the link filter and
	 * the row. If the database stays locked (a rebuild, see Rebuild), the hit
	 * goes to this spool file instead and is written at the next
	 * successful write or at the end of the rebuild; no visitor waits longer
	 * than before. A check other than LinkFilter cannot be spooled: such a hit
	 * is lost and logged, as before. Throws everything else.
	 *
	 * @param callable(): Store $open
	 * @param array<string,string|int> $row
	 */
	public static function commit(callable $open, string $file, array $row, callable|null $counts, string $session, \DateTimeImmutable $now): void
	{
		$deadline = hrtime(true) + Store::BUSY_TIMEOUT_MS * 1_000_000;
		$left = static fn (): int => max(1, intdiv($deadline - hrtime(true), 1_000_000));
		try {
			$store = $open();
			try {
				$store->waitUpTo($left())->readSpool();
			} catch (Throwable $e) {
				if (self::locked($e)) {
					throw $e;
				}
				// A spool that cannot be read must not stop this hit.
				error_log('Kizami: spool not read: ' . $e->getMessage());
			}
			$store->waitUpTo($left());
			// Optional check of the caller (link filter of the redirects).
			// Discarding is never silent: the daily counter kizami_discarded
			// shows up in the dashboard and the report.
			if ($counts !== null && $counts($store, $session, $now) !== true) {
				$store->discard($now->format('Y-m-d'));
				return;
			}
			$store->write($row);
		} catch (Throwable $e) {
			if (!self::locked($e) || ($counts !== null && !($counts instanceof LinkFilter))) {
				throw $e;
			}
			if (!self::add($file, $row, $counts instanceof LinkFilter ? $counts->spoolCheck() : '')) {
				throw new RuntimeException('Kizami: spool file not writable, hit lost: ' . $e->getMessage(), 0, $e);
			}
			return;
		}
		// The rebuild of the file, after the response (Rebuild).
		Rebuild::afterCount($store, $file, $now);
	}

	/** True when $e, or an exception it wraps, says the database was locked (SQLITE_BUSY, SQLITE_LOCKED). */
	public static function locked(Throwable $e): bool
	{
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			if (preg_match('/database is locked|database table is locked/i', $cause->getMessage()) === 1) {
				return true;
			}
		}
		return false;
	}

	/** The spool file of the database $dbPath. */
	public static function path(string $dbPath): string
	{
		return dirname($dbPath) . '/' . self::FILE;
	}
}
