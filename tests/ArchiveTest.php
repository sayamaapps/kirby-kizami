<?php

/** Independently tests archiveal contract; only disposable SQLite fixtures.
 * ddev exec php tests/ArchiveTest.php
 */
require_once __DIR__ . '/../src/Store.php';
use Kizami\Store;
// Never touch application storage or retain test identifiers.

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class ArchiveTest extends TestCase
{
	private static function archiveCheck(string $label, mixed $expected, mixed $actual): void {
	    global $checks;
	    if ($expected !== $actual) {
	        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	    }
	    ++$checks; Assert::assertTrue(true);
	}

	private static function archiveCase(string $name, callable $test): void {
	    global $root, $failures;
	    $dir = $root . '/' . $name;
	    mkdir($dir, 0700);
	    try { $test($dir); }
	    catch (Throwable $e) { $failures[] = $name . ': ' . $e->getMessage(); }
	}

	private static function archiveDb(string $dir): SQLite3 {
	    $db = new SQLite3($dir . '/' . Store::FILE);
	    $db->enableExceptions(true);
	    return $db;
	}

	private static function archiveRows(SQLite3 $db, string $sql): array {
	    $result = $db->query($sql);
	    $rows = [];
	    while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; }
	    $result->finalize();
	    return $rows;
	}

	private static function archiveEvent(SQLite3 $db, string $utc, string $hash = 'device', string $type = 'page', string $action = 'view'): void {
	    $stmt = $db->prepare('INSERT INTO kizami_events (created_at,type,action,path,target,referrer,utm_source,utm_medium,utm_campaign,session) VALUES (:utc,:type,:action,:private,:private,:private,:private,:private,:private,:hash)');
	    foreach (['utc'=>$utc,'type'=>$type,'action'=>$action,'private'=>'PRIVATE-MARKER','hash'=>$hash] as $key=>$value) {
	        $stmt->bindValue(':' . $key, $value, SQLITE3_TEXT);
	    }
	    $stmt->execute()->finalize();
	    $stmt->close();
	}

	private static function archiveUtc(DateTimeImmutable $time): string {
	    return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	private static function archiveCli(array $args): array {
	    $process = proc_open([PHP_BINARY, __DIR__ . '/../maintenance.php', ...$args],
	        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
	    fclose($pipes[0]);
	    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
	    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
	    return [proc_close($process), $out, $err];
	}

	public function testSuite(): void
	{
		global $checks, $root, $failures;
		$root = sys_get_temp_dir() . '/kizami-archivee-' . bin2hex(random_bytes(8));
		mkdir($root, 0700);
		$checks = 0;
		$failures = [];
		self::archiveCase('migration', function ($dir) {
		    $db = self::archiveDb($dir);
		    // Minimal events table only: neither archivee nor maintenance table exists.
		    $db->exec("CREATE TABLE kizami_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        type TEXT NOT NULL CHECK(type IN ('page','redirect')),
        action TEXT CHECK(action IN ('view','click')), path TEXT, target TEXT,
        referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, session TEXT)");
		    self::archiveEvent($db, '2018-02-03 12:00:00');
		    $store = new Store($dir . '/' . Store::FILE);
		    // Reopen: SQLite 3.7.17 answers PRAGMA table_info from the schema cache of
		    // a connection opened before the migration (empty). That only affects
		    // this test connection; normal queries notice the schema change.
		    $db->close();
		    $db = self::archiveDb($dir);
		    $columns = self::archiveRows($db, 'PRAGMA table_info(kizami_days)');
		    self::archiveCheck('Archive contains exactly four non-identifying columns', ['day','views','clicks','devices'], array_column($columns, 'name'));
		    self::archiveCheck('Counter columns have integer affinity', ['TEXT','INTEGER','INTEGER','INTEGER'], array_column($columns, 'type'));
		    self::archiveCheck('Archive day is the primary key', 1, $columns[0]['pk']);
		    self::archiveCheck('Migration retains existing raw event until archiveal', 1, $db->querySingle('SELECT count(*) FROM kizami_events'));
		    self::archiveCheck('Archival returns removed row count', 1, $store->compact());
		    self::archiveCheck('Migrated old event becomes daily counters', [['day'=>'2018-02-03','views'=>1,'clicks'=>0,'devices'=>1]], self::archiveRows($db, 'SELECT * FROM kizami_days'));
		    self::archiveCheck('Raw identifying data removed', 0, $db->querySingle('SELECT count(*) FROM kizami_events'));
		    self::archiveCheck('No IDs, paths, campaign values or hashes in archivee', false, str_contains(json_encode(self::archiveRows($db, 'SELECT * FROM kizami_days')), 'PRIVATE-MARKER'));
		    self::archiveCheck('Repeated cleanup deletes nothing', 0, $store->compact());
		    self::archiveCheck('Repeated cleanup does not duplicate counters', 1, $db->querySingle('SELECT sum(views) FROM kizami_days'));
		});
		self::archiveCase('calendar_boundary', function ($dir) {
		    $store = new Store($dir . '/' . Store::FILE); $db = self::archiveDb($dir);
		    $cutoff = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->modify('-13 months');
		    self::archiveEvent($db, self::archiveUtc($cutoff->modify('-1 second')));
		    self::archiveEvent($db, self::archiveUtc($cutoff));
		    self::archiveEvent($db, self::archiveUtc($cutoff->modify('+12 hours')));
		    self::archiveCheck('Only the preceding complete Berlin day is archiveed', 1, $store->compact());
		    self::archiveCheck('Exact cutoff midnight and later events stay raw', 2, $db->querySingle('SELECT count(*) FROM kizami_events'));
		    self::archiveCheck('Archive day uses Berlin timezone', $cutoff->modify('-1 day')->format('Y-m-d'), $db->querySingle('SELECT day FROM kizami_days'));
		    self::archiveCheck('Combined totals count boundary once and devices per day', ['views'=>3,'clicks'=>0,'devices'=>2], $store->longTerm()['total']);
		});
		self::archiveCase('dst_and_distinct_days', function ($dir) {
		    $store = new Store($dir . '/' . Store::FILE); $db = self::archiveDb($dir);
		    foreach (['2020-03-28 23:30:00','2020-03-29 01:30:00','2020-03-29 22:30:00',
		              '2020-10-24 22:30:00','2020-10-25 00:30:00','2020-10-25 01:30:00','2020-10-25 23:30:00'] as $utc) {
		        self::archiveEvent($db, $utc, 'same-hash');
		    }
		    self::archiveCheck('Raw long-term devices already count distinct per Berlin day', ['views'=>7,'clicks'=>0,'devices'=>4], $store->longTerm()['total']);
		    self::archiveCheck('All DST fixture rows archiveed', 7, $store->compact());
		    self::archiveCheck('Spring/fall transitions preserve correct days and distinct count', [
		        ['day'=>'2020-03-29','views'=>2,'clicks'=>0,'devices'=>1],
		        ['day'=>'2020-03-30','views'=>1,'clicks'=>0,'devices'=>1],
		        ['day'=>'2020-10-25','views'=>3,'clicks'=>0,'devices'=>1],
		        ['day'=>'2020-10-26','views'=>1,'clicks'=>0,'devices'=>1],
		    ], self::archiveRows($db, 'SELECT * FROM kizami_days ORDER BY day'));
		    self::archiveCheck('Archival preserves overall counts', ['views'=>7,'clicks'=>0,'devices'=>4], $store->longTerm()['total']);
		    self::archiveCheck('Months sorted newest first', [
		        ['month'=>'2020-10','views'=>4,'clicks'=>0,'devices'=>2],
		        ['month'=>'2020-03','views'=>3,'clicks'=>0,'devices'=>2],
		    ], $store->longTerm()['months']);
		});
		self::archiveCase('raw_plus_archivee', function ($dir) {
		    $store = new Store($dir . '/' . Store::FILE); $db = self::archiveDb($dir);
		    self::archiveEvent($db, '2018-02-03 12:00:00', 'a');
		    self::archiveEvent($db, '2018-02-03 12:01:00', 'a');
		    self::archiveEvent($db, '2018-02-03 12:02:00', 'a', 'redirect', 'click');
		    self::archiveEvent($db, '2018-02-03 12:03:00', 'b', 'redirect', 'view');
		    self::archiveCheck('All redirects count as clicks, only page views as visits', ['views'=>2,'clicks'=>2,'devices'=>1], $store->longTerm()['total']);
		    $store->compact();
		    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
		    $yesterday = $today->modify('-1 day');
		    self::archiveEvent($db, self::archiveUtc($today->modify('+1 hour')), 'same');
		    self::archiveEvent($db, self::archiveUtc($today->modify('+2 hours')), 'same');
		    self::archiveEvent($db, self::archiveUtc($today->modify('+3 hours')), '', 'redirect', 'click');
		    self::archiveEvent($db, self::archiveUtc($yesterday->modify('+1 hour')), 'same');
		    self::archiveCheck('Raw+archivee counts and per-day page visits combine correctly', ['views'=>5,'clicks'=>3,'devices'=>3], $store->longTerm()['total']);
		    $months = $store->longTerm()['months'];
		    $names = array_column($months, 'month'); $sorted = $names; rsort($sorted);
		    self::archiveCheck('Mixed months newest first', $sorted, $names);
		    $monthly = array_column($months, null, 'month');
		    self::archiveCheck('Historical month preserves page visits', ['month'=>'2018-02','views'=>2,'clicks'=>2,'devices'=>1], $monthly['2018-02']);
		    self::archiveCheck('Repeated cleanup preserves long-term output', 0, $store->compact());
		    self::archiveCheck('Still exactly one old daily row', 1, $db->querySingle('SELECT count(*) FROM kizami_days'));
		});
		foreach (['insert','delete'] as $failure) {
		    self::archiveCase('atomic_' . $failure, function ($dir) use ($failure) {
		        $store = new Store($dir . '/' . Store::FILE); $db = self::archiveDb($dir);
		        self::archiveEvent($db, '2018-02-03 12:00:00');
		        self::archiveEvent($db, '2018-02-04 12:00:00');
		        $db->exec("INSERT INTO kizami_days (day,views,clicks,devices) VALUES ('2017-01-01',8,3,2)");
		        $archiveeBefore = self::archiveRows($db, 'SELECT * FROM kizami_days ORDER BY day');
		        $rawBefore = self::archiveRows($db, 'SELECT * FROM kizami_events ORDER BY id');
		        if ($failure === 'insert') {
		            $db->exec("CREATE TRIGGER fail_archivee BEFORE INSERT ON kizami_days WHEN NEW.day='2018-02-04' BEGIN SELECT RAISE(ABORT, 'injected insert failure'); END");
		        } else {
		            $db->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON kizami_events WHEN OLD.created_at='2018-02-04 12:00:00' BEGIN SELECT RAISE(ABORT, 'injected delete failure'); END");
		        }
		        $thrown = false;
		        try { $store->compact(); } catch (Throwable) { $thrown = true; }
		        self::archiveCheck('Injected ' . $failure . ' failure propagates', true, $thrown);
		        self::archiveCheck('No partly deleted raw rows', $rawBefore, self::archiveRows($db, 'SELECT * FROM kizami_events ORDER BY id'));
		        self::archiveCheck('Preexisting archivee intact, no partial new counters', $archiveeBefore, self::archiveRows($db, 'SELECT * FROM kizami_days ORDER BY day'));
		        $db->exec('DROP TRIGGER ' . ($failure === 'insert' ? 'fail_archivee' : 'fail_delete'));
		        self::archiveCheck('Transaction released and retry succeeds', 2, $store->compact());
		        self::archiveCheck('Retry counts each row once', ['views'=>10,'clicks'=>3,'devices'=>4], $store->longTerm()['total']);
		    });
		}
		self::archiveCase('late_backfill_is_rejected', function ($dir) {
		    $store = new Store($dir . '/' . Store::FILE); $db = self::archiveDb($dir);
		    self::archiveEvent($db, '2018-02-03 12:00:00', 'already-archiveed');
		    $store->compact();
		    $archiveeBefore = self::archiveRows($db, 'SELECT * FROM kizami_days');
		    // Once hashes are deleted, correct deduplication of a late import is unknown.
		    // The supported response is a visible failure with both datasets retained.
		    self::archiveEvent($db, '2018-02-03 13:00:00', 'already-archiveed');
		    $thrown = false;
		    try { $store->compact(); } catch (Throwable) { $thrown = true; }
		    self::archiveCheck('Late same-day import is rejected rather than guessed', true, $thrown);
		    self::archiveCheck('Late import does not change archivee counters', $archiveeBefore, self::archiveRows($db, 'SELECT * FROM kizami_days'));
		    self::archiveCheck('Late imported event remains recoverable', 1, $db->querySingle('SELECT count(*) FROM kizami_events'));
		});
		self::archiveCase('daily_cli_backup_restore', function ($dir) {
		    $store = new Store($dir . '/' . Store::FILE); $db = self::archiveDb($dir);
		    self::archiveEvent($db, '2018-02-03 12:00:00', 'a');
		    $store->write(['type'=>'page','action'=>'view','path'=>'/test','session'=>'today']);
		    self::archiveCheck('Daily cleanup archivees independently of dashboard', 1, $db->querySingle('SELECT count(*) FROM kizami_days'));
		    self::archiveCheck('Daily cleanup removes only old raw event', 1, $db->querySingle('SELECT count(*) FROM kizami_events'));
		    self::archiveEvent($db, '2018-02-04 12:00:00', 'a', 'redirect', 'click');
		    [$exit, , $err] = self::archiveCli(['compact', $dir]);
		    self::archiveCheck('CLI archivees successfully: ' . $err, 0, $exit);
		    self::archiveCheck('CLI preserves previous archiveed day', 2, $db->querySingle('SELECT count(*) FROM kizami_days'));
		    $expected = $store->longTerm();
		    [$exit, , $err] = self::archiveCli(['snapshot', $dir, $dir . '/snapshot.sqlite']);
		    self::archiveCheck('CLI backup succeeds: ' . $err, 0, $exit);
		    mkdir($dir . '/restore', 0700);
		    copy($dir . '/snapshot.sqlite', $dir . '/restore/' . Store::FILE);
		    $restored = new Store($dir . '/restore/' . Store::FILE);
		    $restoreDb = self::archiveDb($dir . '/restore');
		    self::archiveCheck('Restored snapshot is structurally sound', 'ok', $restoreDb->querySingle('PRAGMA integrity_check'));
		    self::archiveCheck('Restored archivee contains every daily aggregate', self::archiveRows($db, 'SELECT * FROM kizami_days ORDER BY day'), self::archiveRows($restoreDb, 'SELECT * FROM kizami_days ORDER BY day'));
		    self::archiveCheck('Backup+restore preserve raw and historical figures', $expected, $restored->longTerm());
		    self::archiveCheck('Restore cleanup cannot duplicate archivee counts', 0, $restored->compact());
		    self::archiveCheck('Counts unchanged after restored cleanup', $expected, $restored->longTerm());
		});
		self::archiveCase('cli_daily_key', function ($dir) {
		    new Store($dir . '/' . Store::FILE);
		    $keyFile = $dir . '/secret.txt';
		    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
		    // The job runs as another user than the web server: it removes a stale
		    // key and never creates one.
		    [$exit, $out, $err] = self::archiveCli(['compact', $dir]);
		    self::archiveCheck('CLI without a key file succeeds: ' . $err, 0, $exit);
		    self::archiveCheck('… reports no daily key', true, str_contains($out, 'no daily key'));
		    self::archiveCheck('… and creates none', false, file_exists($keyFile));
		    file_put_contents($keyFile, "2010-01-01\nSTALE-SECRET-VALUE");
		    [$exit, $out, $err] = self::archiveCli(['compact', $dir]);
		    self::archiveCheck('CLI with a stale key succeeds: ' . $err, 0, $exit);
		    self::archiveCheck('… reports it removed', true, str_contains($out, 'stale daily key removed'));
		    self::archiveCheck('… and the file is gone', false, file_exists($keyFile));
		    $current = $today . "\nCURRENT-SECRET-VALUE";
		    file_put_contents($keyFile, $current);
		    [$exit, $out, $err] = self::archiveCli(['compact', $dir]);
		    self::archiveCheck('CLI with a current key succeeds: ' . $err, 0, $exit);
		    self::archiveCheck('… reports it current', true, str_contains($out, 'daily key current') && !str_contains($out, 'stale'));
		    self::archiveCheck('… and leaves the file untouched', $current, (string) file_get_contents($keyFile));
		});
		self::archiveCase('empty', function ($dir) {
		    $store = new Store($dir . '/' . Store::FILE);
		    self::archiveCheck('Empty installation has honest zero totals', ['total'=>['views'=>0,'clicks'=>0,'devices'=>0], 'months'=>[]], $store->longTerm());
		    self::archiveCheck('Empty cleanup is a no-op', 0, $store->compact());
		});
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
		    if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
		}
		rmdir($root);
		$this->assertSame([], $failures);
	}
}
