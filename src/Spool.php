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
 * get, nothing more; its first line names the batch (a random name, see
 * batch()). It holds at most MAX_BYTES; further hits are dropped until it has
 * been read (logged at most once a day). Lines older than the retention
 * period are dropped when the file is read. Appending takes an exclusive
 * flock(); reading takes the database's write lock first and then the same
 * flock(), reads at most READ_LINES lines and records batch and position in
 * the database in the same transaction (Store::readSpool()), so nothing is
 * counted twice, not even when the file could not be emptied.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Spool
{
	public const FILE = 'spool.jsonl';

	/** Size in bytes from which add() drops hits instead of appending them. */
	public const MAX_BYTES = 5_000_000;

	/** Longest line read; a longer one is skipped as unreadable. A hit is far shorter (paths are cut at 255 bytes). */
	public const MAX_LINE = 65536;

	/** Lines Store::readSpool() reads at most per call; the rest waits for the next one. */
	public const READ_LINES = 1000;

	/**
	 * A hit for later. $check: 'pageview' for a link click without a confirmed
	 * user action, which only counts if the same day identifier has viewed a
	 * page that day (decided when the file is read, see LinkFilter). Returns
	 * false when the file cannot be written. A file of MAX_BYTES or more drops
	 * the hit and returns true: the error log says so when the file fills up
	 * and then at most once a day, not once per hit.
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
		// 'a+': every write appends, and the last byte can be read.
		$handle = fopen($file, 'a+');
		if ($handle === false) {
			return false;
		}
		try {
			if ($new) {
				chmod($file, 0600);
			}
			$line = json_encode(['row' => $row] + ($check !== '' ? ['check' => $check] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			if (!is_string($line) || !flock($handle, LOCK_EX)) {
				return false;
			}
			$stat = fstat($handle);
			if ($stat === false) {
				return false;
			}
			$size = $stat['size'];
			if ($size >= self::MAX_BYTES) {
				// Logging sets the file's time; while it is full nothing else does.
				if (time() - $stat['mtime'] >= 86400) {
					self::logFull();
					touch($file);
				}
				return true;
			}
			$out = self::prefix($handle, $size) . $line . "\n";
			if (fwrite($handle, $out) !== strlen($out) || !fflush($handle)) {
				return false;
			}
			if ($size + strlen($out) >= self::MAX_BYTES) {
				self::logFull();
			}
			return true;
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * The batch name in the first line of $handle, '' without one. add()
	 * writes it into an empty file, so a file emptied after reading gets a
	 * new one and an old position (Store::readSpool()) no longer applies.
	 * Leaves $handle after that line, or at the start when there is none.
	 *
	 * @param resource $handle
	 */
	public static function batch($handle): string
	{
		rewind($handle);
		$first = fgets($handle, self::MAX_LINE + 1);
		$header = is_string($first) && str_ends_with($first, "\n") ? json_decode($first, true) : null;
		if (is_array($header) && is_string($header['spool'] ?? null) && preg_match('/^[0-9a-f]{16}$/', $header['spool']) === 1) {
			return $header['spool'];
		}
		rewind($handle);
		return '';
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

	/**
	 * The next line of $handle without its newline; null at the end, false
	 * for a line longer than MAX_LINE (skipped to its end, memory stays small
	 * however large the file is).
	 *
	 * @param resource $handle
	 */
	public static function line($handle): string|false|null
	{
		$line = fgets($handle, self::MAX_LINE + 1);
		if ($line === false) {
			return null;
		}
		if (str_ends_with($line, "\n") || feof($handle)) {
			return rtrim($line, "\n");
		}
		do {
			$rest = fgets($handle, self::MAX_LINE + 1);
		} while (is_string($rest) && !str_ends_with($rest, "\n"));
		return false;
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

	private static function logFull(): void
	{
		error_log('Kizami: spool file full (' . self::MAX_BYTES . ' bytes), hits are dropped until it has been read');
	}

	/** The spool file of the database $dbPath. */
	public static function path(string $dbPath): string
	{
		return dirname($dbPath) . '/' . self::FILE;
	}

	/**
	 * What goes before the line: the batch name for an empty file, a newline
	 * when an append that broke off left half a line (otherwise the next hit
	 * would be glued to it and lost).
	 *
	 * @param resource $handle
	 */
	private static function prefix($handle, int $size): string
	{
		if ($size === 0) {
			return '{"spool":"' . bin2hex(random_bytes(8)) . '"}' . "\n";
		}
		fseek($handle, -1, SEEK_END);
		return fread($handle, 1) === "\n" ? '' : "\n";
	}
}
