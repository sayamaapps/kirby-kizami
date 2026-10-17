<?php

namespace Kizami;

/**
 * The hash secret lives on the server, not in git and not as a CI secret. It
 * is created on the first run and sits outside the web root (storage/). No
 * fallback value: if it is missing (directory not creatable or not writable),
 * get() returns an empty string and the caller records nothing.
 *
 * THE SECRET ROTATES DAILY. The file holds "date\nsecret"; if the date is no
 * longer the one passed in, a new secret is generated and the old one is
 * overwritten.
 *
 * The key rotates on the first counted visit of a day, and also through
 * rotate(), which the dashboard, the report and snapshot routes (after
 * authentication) call. expire() removes a stale key where nobody needs a new
 * one: a filtered request (DNT/GPC, bot) and the maintenance job. Without any
 * of these the last key stays on disk, so a site that may go days without a
 * page request needs the maintenance job.
 *
 * Rotation limits linkability. It is no guarantee of anonymity: backups can
 * preserve old keys, and additional knowledge can still allow matching. So
 * never archive the key in your own backups.
 *
 * Accepted cost: at midnight two simultaneous requests can create two
 * secrets; one wins the rename(), the other's rows carry a hash whose secret
 * is lost and count as one extra "distinct visitor". A rounding error in the
 * second after midnight — no reason for a lock file on the write path.
 */
final class Secret
{
    public const FILE = 'secret.txt';

    /**
     * Retire a stale key without a counted visit. Replaces the file when its
     * date is not `$date`; a current key is left untouched. A missing file
     * stays missing (nothing to retire, and an operator route must not create
     * keys on an installation that never counted). False only when a stale key
     * could not be replaced.
     */
    public static function rotate(string $directory, string $date): bool
    {
        if (!is_file($directory . '/' . self::FILE)) {
            return true;
        }
        return self::get($directory, $date) !== '';
    }

    /**
     * Remove a stale key without making a new one. Used where no one needs a
     * key right now (filtered visitors, the CLI job): the CLI runs as another
     * user than the web server, so a key it created would be unreadable for
     * the server, which then makes its own at the next visit anyway. A
     * missing or current file is left alone; a file without a readable date
     * line counts as stale. The counted path keeps rotating through get()'s
     * atomic rename(); the tiny race against a parallel rename() costs at most
     * the one extra "distinct visitor" already accepted above. False only when
     * a stale key could not be removed.
     */
    public static function expire(string $directory, string $date): bool
    {
        $file = $directory . '/' . self::FILE;
        $existing = @file_get_contents($file);
        if (is_string($existing) && explode("\n", trim($existing), 2)[0] === $date) {
            return true;
        }
        if (!file_exists($file)) {
            return true;
        }
        @unlink($file);
        return !file_exists($file);
    }

    public static function get(string $directory, string $date): string
    {
        $file = $directory . '/' . self::FILE;

        $existing = @file_get_contents($file);
        if (is_string($existing) && $existing !== '') {
            [$stamp, $value] = array_pad(explode("\n", trim($existing), 2), 2, '');
            if ($stamp === $date && $value !== '') {
                return $value;
            }
            // Date no longer matches (or the file predates the date line):
            // rotate. Falls through to the regeneration below.
        }

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            error_log('Kizami: secret directory cannot be created: ' . $directory);
            return '';
        }

        $value = bin2hex(random_bytes(32));

        // Atomic: write a temp file in the same directory first, then
        // rename(). Otherwise two parallel runs could leave a half-written
        // file behind. rename() replaces the old secret in one step — that is
        // the rotation.
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $date . "\n" . $value, LOCK_EX) === false) {
            error_log('Kizami: secret not writable in ' . $directory);
            return '';
        }
        @chmod($temp, 0600);

        if (!@rename($temp, $file)) {
            // A parallel run was faster — its file wins, as long as it is
            // from the same day.
            @unlink($temp);
            $existing = @file_get_contents($file);
            if (is_string($existing) && $existing !== '') {
                [$stamp, $other] = array_pad(explode("\n", trim($existing), 2), 2, '');
                if ($stamp === $date && $other !== '') {
                    return $other;
                }
            }
            return '';
        }

        return $value;
    }
}
