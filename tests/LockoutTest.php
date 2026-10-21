<?php
/**
 * Failed-login limit of the dashboard's file login (src/Lockout.php), without Kirby.
 *   php tests/LockoutTest.php
 */
require_once __DIR__ . '/../src/Lockout.php';

use Kizami\Lockout;

$n = 0;
function same($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}
function failsWith(callable $f, string $what): void
{
    global $n;
    try { $f(); } catch (RuntimeException) { $n++; return; }
    throw new RuntimeException($what . ': no exception');
}

$tmp = sys_get_temp_dir() . '/kizami-lockout-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$failed = false;
try {
    $file = $tmp . '/storage/logins.json'; // directory created on demand
    $lock = new Lockout($file, 10, 3600);
    $t = 1_800_000_000;

    same(false, $lock->blocked('203.0.113.7', 'client', $t), 'Fresh state: not blocked');
    same(0, $lock->retryAfter('203.0.113.7', 'client', $t), 'Fresh state: nothing to wait for');

    for ($i = 0; $i < 9; $i++) { $lock->fail('203.0.113.7', 'client', $t + $i); }
    same(false, $lock->blocked('203.0.113.7', 'client', $t + 9), '9 failures: not blocked');
    $lock->fail('203.0.113.7', 'client', $t + 10);
    same(true, $lock->blocked('203.0.113.7', 'client', $t + 11), '10 failures: blocked');
    same(true, $lock->blocked('203.0.113.7', 'someone-else', $t + 11), 'Blocked by address, whatever the name');
    same(true, $lock->blocked('198.51.100.9', 'client', $t + 11), 'Blocked by name from another address');
    same(false, $lock->blocked('198.51.100.9', 'someone-else', $t + 11), 'Other address and other name: not blocked');
    same(3599, $lock->retryAfter('203.0.113.7', 'client', $t + 11), 'Seconds left count from the last failure');
    same(1, $lock->retryAfter('203.0.113.7', 'client', $t + 10 + 3599), 'Last second of the block');
    same(false, $lock->blocked('203.0.113.7', 'client', $t + 10 + 3600), 'Block ends after the timeout');
    same(0, $lock->retryAfter('203.0.113.7', 'client', $t + 10 + 3600), 'Nothing left to wait');

    // Expired entries are pruned from the file, not only ignored.
    $lock->fail('192.0.2.1', 'other', $t + 20000);
    $stored = json_decode((string) file_get_contents($file), true);
    same([1, 1], [count($stored['ip']), count($stored['user'])], 'Old entries pruned on the next write (only the new one left per section)');

    // Fewer than the limit, then the timeout passes: the count starts again.
    $lock2 = new Lockout($tmp . '/two.json', 3, 100);
    $lock2->fail('a', 'u', $t); $lock2->fail('a', 'u', $t + 1);
    same(false, $lock2->blocked('a', 'u', $t + 2), 'Below the limit');
    $lock2->fail('a', 'u', $t + 200);
    same(false, $lock2->blocked('a', 'u', $t + 201), 'Old failures do not add up with a new one');
    $lock2->fail('a', 'u', $t + 202); $lock2->fail('a', 'u', $t + 203);
    same(true, $lock2->blocked('a', 'u', $t + 204), 'Three failures within the window');

    // The file holds hashes only.
    $lock->fail('203.0.113.77', 'secret-client-name', $t + 30000);
    $json = (string) file_get_contents($file);
    same(false, str_contains($json, '203.0.113.77') || str_contains($json, 'secret-client-name'), 'No plain address or name in the file');
    same(true, str_contains($json, Lockout::key('203.0.113.77')) && str_contains($json, Lockout::key('secret-client-name')), 'Entries keyed by hash');
    same(50, strlen(Lockout::key('x')), 'Key is 50 characters, like Kirby\'s');

    // Reading alone does not change a valid file.
    $before = (string) file_get_contents($file);
    $lock->blocked('203.0.113.77', 'secret-client-name', $t + 30001);
    same($before, (string) file_get_contents($file), 'Reading does not rewrite the file');

    // Unusable files fail closed (the caller denies).
    foreach (['not json', '[1,2]', '{"ip":"x"}', '{"ip":{"k":{"time":"1","trials":2}}}', '{"ip":{"k":5}}'] as $garbage) {
        file_put_contents($file, $garbage);
        failsWith(fn () => $lock->blocked('a', 'b', $t), 'Malformed file on read: ' . $garbage);
        failsWith(fn () => $lock->fail('a', 'b', $t), 'Malformed file on write: ' . $garbage);
        same($garbage, (string) file_get_contents($file), 'Malformed file is left alone: ' . $garbage);
    }
    file_put_contents($file, '');
    same(false, $lock->blocked('a', 'b', $t), 'Empty file counts as no failures');
    unlink($file);
    mkdir($file); // a directory where the file should be
    failsWith(fn () => $lock->blocked('a', 'b', $t), 'File that cannot be opened');
    rmdir($file);
    file_put_contents($tmp . '/blocker', 'x');
    failsWith(fn () => (new Lockout($tmp . '/blocker/logins.json'))->fail('a', 'b', $t), 'Directory that cannot be created');

    // Size cap: a flood of invented names cannot grow the file without limit.
    $flood = ['ip' => [], 'user' => []];
    for ($i = 0; $i < 10000; $i++) { $flood['user'][Lockout::key("n$i")] = ['time' => $t + 40000, 'trials' => 1]; }
    file_put_contents($file, json_encode($flood));
    $lock->fail('203.0.113.1', 'one-more', $t + 40001);
    $stored = json_decode((string) file_get_contents($file), true);
    same(10000, count($stored['user']), 'Section capped at 10000 entries');
    same(true, isset($stored['user'][Lockout::key('one-more')]), 'The newest entry survives the cap');
} catch (Throwable $e) {
    fwrite(STDERR, 'Lockout: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($tmp);
}
if ($failed) { exit(1); }
echo "Lockout: all $n checks passed\n";
