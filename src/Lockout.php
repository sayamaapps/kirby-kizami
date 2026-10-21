<?php

namespace Kizami;

/**
 * Limit on failed logins of the dashboard's own file login, modelled on
 * Kirby's: after `$trials` failures from one address or for one user name
 * within `$timeout` seconds, that address / name is blocked until the last
 * failure is `$timeout` seconds old.
 *
 * Kirby's own limit cannot be reused: it keys the per-name half on existing
 * Kirby accounts, and the file login has none. Kirby-free, so it is tested
 * without Kirby; the limits come from Kirby's `auth.trials` / `auth.timeout`.
 *
 * The file holds hashes only (same scheme as Kirby: first 50 characters of
 * SHA-256), no plain address and no user name. Every access runs under an
 * exclusive lock on the file itself: a read-modify-write that two parallel
 * failures cannot both lose, and a reader never sees the half-written file
 * (which a temp file + rename would avoid too, but not the lost update).
 *
 * Fail closed: an unreadable or malformed file throws, and the caller
 * denies. A guess limit that silently stops counting would be no limit.
 */
final class Lockout
{
    public const FILE = 'logins.json';

    /** Upper bound per section, so a flood of invented names cannot grow the file without limit. */
    private const MAX_ENTRIES = 10000;

    public function __construct(
        private readonly string $file,
        private readonly int $trials = 10,
        private readonly int $timeout = 3600
    ) {
    }

    /** Hash of an address or user name, as Kirby keys its own entries. */
    public static function key(string $value): string
    {
        return substr(hash('sha256', $value), 0, 50);
    }

    /** Seconds until the block ends, 0 when neither address nor user name is blocked. @throws \RuntimeException */
    public function retryAfter(string $ip, string $user, ?int $now = null): int
    {
        $now ??= time();
        // Nothing counted yet: reading alone does not create the file.
        if (!file_exists($this->file)) {
            return 0;
        }
        $left = 0;
        $this->update(function (array $entries) use ($ip, $user, $now, &$left): ?array {
            foreach ([['ip', $ip], ['user', $user]] as [$section, $value]) {
                $entry = $entries[$section][self::key($value)] ?? null;
                if ($entry !== null && $entry['trials'] >= $this->trials) {
                    $left = max($left, $entry['time'] + $this->timeout - $now);
                }
            }
            return null;
        }, $now);
        return max(0, $left);
    }

    public function blocked(string $ip, string $user, ?int $now = null): bool
    {
        return $this->retryAfter($ip, $user, $now) > 0;
    }

    /** Count one failed login against the address and the user name. @throws \RuntimeException */
    public function fail(string $ip, string $user, ?int $now = null): void
    {
        $now ??= time();
        $this->update(function (array $entries) use ($ip, $user, $now): array {
            foreach ([['ip', $ip], ['user', $user]] as [$section, $value]) {
                $key = self::key($value);
                $entries[$section][$key] = [
                    'time'   => $now,
                    'trials' => ($entries[$section][$key]['trials'] ?? 0) + 1,
                ];
                if (count($entries[$section]) > self::MAX_ENTRIES) {
                    uasort($entries[$section], fn ($a, $b) => $b['time'] <=> $a['time']);
                    $entries[$section] = array_slice($entries[$section], 0, self::MAX_ENTRIES, true);
                }
            }
            return $entries;
        }, $now);
    }

    /**
     * Read under lock, drop expired entries, let $change return new entries
     * (written back) or null (nothing to write).
     *
     * @throws \RuntimeException
     */
    private function update(callable $change, int $now): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('directory missing');
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('cannot open');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('cannot lock');
            }
            $raw = stream_get_contents($handle);
            if ($raw === false) {
                throw new \RuntimeException('cannot read');
            }
            $entries = ['ip' => [], 'user' => []];
            if (trim($raw) !== '') {
                $entries = self::parse($raw);
            }
            // Pruned on read, like Kirby: an old failure counts for nothing.
            $before = $entries;
            foreach ($entries as $section => $list) {
                $entries[$section] = array_filter($list, fn ($e) => $e['time'] + $this->timeout > $now);
            }

            $new = $change($entries) ?? ($entries === $before ? null : $entries);
            if ($new !== null) {
                $json = json_encode($new, JSON_UNESCAPED_SLASHES);
                if ($json === false || !ftruncate($handle, 0) || fseek($handle, 0) !== 0
                    || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                    throw new \RuntimeException('cannot write');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{ip:array<string,array{time:int,trials:int}>,user:array<string,array{time:int,trials:int}>} */
    private static function parse(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException('malformed');
        }
        $entries = ['ip' => [], 'user' => []];
        foreach ($entries as $section => $_) {
            $list = $data[$section] ?? [];
            if (!is_array($list)) {
                throw new \RuntimeException('malformed');
            }
            foreach ($list as $key => $entry) {
                if (!is_string($key) || !is_array($entry) || !is_int($entry['time'] ?? null) || !is_int($entry['trials'] ?? null)) {
                    throw new \RuntimeException('malformed');
                }
                $entries[$section][$key] = ['time' => $entry['time'], 'trials' => $entry['trials']];
            }
        }
        return $entries;
    }
}
