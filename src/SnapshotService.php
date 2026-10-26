<?php

namespace Sayamaapps\Kizami;

use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * GET /k/snapshot — a consistent copy of the database as a download, for
 * backup jobs on hosts without a shell and without cron (shared hosting).
 *
 * Why a route and not only bin/kizami-snapshot: the backup job fetches the
 * data from outside. Without a shell it can't call the CLI, and a
 * kizami.sqlite copied via FTP is not consistent while WAL is active
 * (committed transactions may still sit in kizami.sqlite-wal). The route
 * uses the same online backup API as the CLI (SQLite3::backup, since SQLite
 * 3.6.11, so also on 3.7.17), checks the copy with integrity_check and
 * returns it. The intermediate file lives in storage/kizami/ (outside the
 * web root).
 *
 * The copy is streamed, never loaded into a string (SnapshotResponse), so the
 * memory use does not depend on the size of the database. The file is
 * unlinked as soon as it is open — the open handle still reads it — so
 * nothing stays in the storage if the process dies while sending. Leftovers
 * of requests that died earlier (a fatal error, a killed process) are
 * removed after one hour at the next request (sweep()).
 *
 * Off by default, twice: without `kizami.active` AND `kizami.snapshot` the
 * route answers with Kirby's real 404. Login like /k/report (Basic Auth
 * against a Kirby account, HTTPS only), roles from `kizami.snapshotRoles`
 * (default: the bundled role `kizami-snapshot`). The raw data is more
 * sensitive than the report — so only switch the route on where a backup
 * job needs it.
 *
 * Never included: storage/kizami/secret.txt (the daily key).
 *
 * @package Sayamaapps\Kizami
 */
class SnapshotService
{
	public const ROLE = 'kizami-snapshot';

	/** Leftover `.snapshot-*` files older than this many seconds are removed. */
	public const STALE_AFTER = 3600;
	private const SUFFIXES = ['', '-wal', '-shm', '-journal'];

	/** Removes a snapshot copy and its SQLite side files, where they exist. */
	public static function cleanup(string $temp): void
	{
		foreach (self::SUFFIXES as $suffix) {
			if (is_file($temp . $suffix)) {
				@unlink($temp . $suffix);
			}
		}
	}

	public static function respond(App $kirby): Response
	{
		if (Options::get($kirby, 'active', false) !== true || Options::get($kirby, 'snapshot', false) !== true) {
			return Runtime::notFound();
		}
		$temp = null;
		try {
			ReportService::authenticate($kirby, (array)Options::get($kirby, 'snapshotRoles', [self::ROLE]));
			Runtime::rotateSecret();
			self::sweep(Runtime::directory());
			$db = Runtime::directory() . '/' . Store::FILE;
			if (!is_file($db)) {
				throw new ReportError('No data yet.', 404);
			}
			$temp = Runtime::directory() . '/.snapshot-' . bin2hex(random_bytes(8)) . '.sqlite';
			// Safety net for a fatal error between creating the copy and the
			// answer: `finally` does not run then, a shutdown function does.
			register_shutdown_function([self::class, 'cleanup'], $temp);
			(new Store($db, Store::journal(Options::get($kirby, 'journal'))))->snapshot($temp);
			$handle = fopen($temp, 'rb');
			if ($handle === false) {
				throw new \RuntimeException('Snapshot copy not readable');
			}
			// Unlink right away: on POSIX the open handle stays readable, and
			// from here on no file is left in the storage, even if the process
			// dies while sending. Where unlinking an open file fails (Windows),
			// SnapshotResponse::send() removes it after sending, and sweep()
			// at the latest.
			@unlink($temp);
			$context = hash_init('sha256');
			hash_update_stream($context, $handle);
			rewind($handle);
			$size = (int)(fstat($handle)['size'] ?? 0);
			$day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
			return new SnapshotResponse($handle, $temp, [
				'Content-Disposition' => 'attachment; filename="kizami-' . $day . '.sqlite"',
				'Content-Length' => (string)$size,
				'X-Kizami-Sha256' => hash_final($context),
				'Cache-Control' => 'no-store',
				'X-Robots-Tag' => 'noindex, nofollow, noarchive',
			]);
		} catch (ReportError $e) {
			return ReportService::json(['ok' => false, 'error' => $e->getMessage()], $e->status);
		} catch (Throwable $e) {
			error_log('Kizami: snapshot not available: ' . $e->getMessage());
			Runtime::logError($e);
			return ReportService::json(['ok' => false, 'error' => 'Internal error.'], 500);
		} finally {
			if ($temp !== null) {
				self::cleanup($temp);
			}
		}
	}

	/**
	 * Removes `.snapshot-*` files (and their side files) in $directory whose
	 * modification time is older than STALE_AFTER seconds: what a request
	 * that died between creating the copy and sending it left behind. Younger
	 * files stay — a parallel download may still be running.
	 *
	 * @return int number of files removed
	 */
	public static function sweep(string $directory): int
	{
		$names = @scandir($directory);
		if ($names === false) {
			return 0;
		}
		$limit = time() - self::STALE_AFTER;
		$removed = 0;
		foreach ($names as $name) {
			$file = $directory . '/' . $name;
			if (str_starts_with($name, '.snapshot-') && is_file($file) && (int)@filemtime($file) < $limit && @unlink($file)) {
				$removed++;
			}
		}
		return $removed;
	}
}
