<?php

/**
 * Package contract and the one-time migration from the pre-1.0 copy
 * `kennzahlen` — partly with real Kirby.
 *   php tests/MigrationTest.php
 *
 * - plugin ID, roles and routes under their English names
 * - options only as `kizami.*` (the `kennzahlen.*` fallback is gone)
 * - language, brand and preview access options
 * - storage/kennzahlen/ moves to storage/kizami/ with renamed files, also
 *   with a hot WAL file next to the database
 * - the German schema becomes the English one, with every value mapped and
 *   every row kept, from each pre-1.0 state
 * - an old copy next to the package keeps the package inactive instead of
 *   counting twice
 */
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
/**
 * A database as the pre-1.0 copy left it. $columns limits the events table
 * to an older state (before device, position and seconds existed).
 */

use PHPUnit\Framework\TestCase;
use Sayamaapps\Kizami\Migration;
use Sayamaapps\Kizami\Options;
use Sayamaapps\Kizami\Store;

#[CoversClass(\Sayamaapps\Kizami\Migration::class)]
#[CoversClass(\Sayamaapps\Kizami\Options::class)]
#[CoversClass(\Sayamaapps\Kizami\Runtime::class)]
#[CoversClass(\Sayamaapps\Kizami\Store::class)]
#[RunTestsInSeparateProcesses]
final class MigrationTest extends TestCase
{
	private static function expect($expected, $actual, string $what): void
	{
		global $n;
		if ($expected !== $actual) {
			Assert::fail($what . ': ' . var_export($actual, true));
		}
		$n++;
		Assert::assertTrue(true);
	}

	private static function fixtureSite(string $tmp): void
	{
		foreach (['public', 'site/plugins', 'site/templates', 'site/config', 'site/accounts', 'content/home', 'content/error', 'storage'] as $dir) {
			mkdir($tmp . '/' . $dir, 0700, true);
		}
		file_put_contents($tmp . '/content/site.txt', "Title: Example\n");
		file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
		file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
		file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture";');
		file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
	}

	private static function legacyDatabase(string $file, bool $full = true): SQLite3
	{
		$db = new SQLite3($file);
		$db->exec("CREATE TABLE ereignisse (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
        typ TEXT NOT NULL CHECK (typ IN ('seite','weiterleitung','fehlseite','bild','dauer')),
        aktion TEXT CHECK (aktion IN ('aufruf','klick','sichtbar')),
        pfad TEXT, ziel TEXT, referrer TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, sitzung TEXT"
			. ($full ? ", geraet TEXT NOT NULL DEFAULT '', position TEXT NOT NULL DEFAULT '', sekunden INTEGER NOT NULL DEFAULT 0" : '') . ')');
		$db->exec('CREATE INDEX idx_ereignisse_erstellt ON ereignisse(erstellt)');
		$db->exec('CREATE TABLE kennzahlen_tage (tag TEXT PRIMARY KEY NOT NULL, aufrufe INTEGER NOT NULL, klicks INTEGER NOT NULL, geraete INTEGER NOT NULL)');
		$db->exec("INSERT INTO kennzahlen_tage VALUES ('2020-01-01', 7, 2, 3)");
		if ($full) {
			$db->exec('CREATE TABLE kennzahlen_wartung (id INTEGER PRIMARY KEY CHECK (id=1), tag TEXT NOT NULL)');
			$db->exec("INSERT INTO kennzahlen_wartung VALUES (1, '2026-10-07')");
			$db->exec('CREATE TABLE kennzahlen_verworfen (tag TEXT PRIMARY KEY NOT NULL, anzahl INTEGER NOT NULL)');
			$db->exec("INSERT INTO kennzahlen_verworfen VALUES ('2026-10-06', 4)");
			$rows = [
				"(10, '2026-10-06 08:00:00', 'seite', 'aufruf', '/', '', '(intern)', 'qr', '', '', 'h1', 'mobil', '', 0)",
				"(11, '2026-10-06 08:01:00', 'weiterleitung', 'klick', '/', 'anruf', '', '', '', '', 'h1', 'mobil', 'kopf', 0)",
				"(12, '2026-10-06 08:02:00', 'weiterleitung', 'klick', '/kontakt', 'route', '', '', '', '', 'h1', 'mobil', 'kontakt', 0)",
				"(13, '2026-10-06 08:03:00', 'fehlseite', 'aufruf', '/alt', '', '', '', '', '', 'h1', 'desktop', '', 0)",
				"(14, '2026-10-06 08:04:00', 'bild', 'klick', '/', 'hof', '', '', '', '', 'h1', 'tablet', 'fuss', 0)",
				"(15, '2026-10-06 08:05:00', 'dauer', 'sichtbar', '/', '', '', '', '', '', 'h1', '', 'inhalt', 42)",
			];
			$db->exec('INSERT INTO ereignisse (id, erstellt, typ, aktion, pfad, ziel, referrer, utm_source, utm_medium, utm_campaign, sitzung, geraet, position, sekunden) VALUES ' . implode(', ', $rows));
		} else {
			$db->exec("INSERT INTO ereignisse (id, erstellt, typ, aktion, pfad, ziel, referrer, utm_source, utm_medium, utm_campaign, sitzung)
            VALUES (3, '2025-01-02 10:00:00', 'seite', 'aufruf', '/menue', '', 'google.com', '', '', '', 'h9')");
		}
		return $db;
	}

	private static function removeTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			if ($file->isDir() && !$file->isLink()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($path);
	}

	public function testSuite(): void
	{
		global $n;
		$base = dirname(__DIR__);
		require $base . '/vendor/autoload.php';
		$n = 0;
		$tmp = sys_get_temp_dir() . '/kizami-migration-' . bin2hex(random_bytes(6));
		$failed = false;
		try {
			self::fixtureSite($tmp);
			symlink($base, $tmp . '/site/plugins/kizami');
			$roots = [
				'index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site',
				'storage' => $tmp . '/storage', 'accounts' => $tmp . '/site/accounts',
			];
			// HTTPS: the dashboard refuses plain HTTP.
			$app = fn (array $options = [], array $request = []) => new Kirby\Cms\App([
				'roots' => $roots, 'options' => ['url' => 'https://example.invalid'] + $options, 'request' => $request,
			]);

			// First app: report and snapshot on — the roles must exist then.
			$kirby = $app(['kizami.active' => true, 'kizami.report' => true, 'kizami.snapshot' => true]);
			self::expect(true, $kirby->plugin('sayamaapps/kizami') !== null, 'plugin ID sayamaapps/kizami');
			self::expect(true, isset($kirby->extensions('blueprints')['users/kizami-report']), 'role kizami-report via kizami.report');
			self::expect(true, isset($kirby->extensions('blueprints')['users/kizami-snapshot']), 'role kizami-snapshot via kizami.snapshot');
			$patterns = array_column($kirby->extensions('routes'), 'pattern');
			foreach (['k/image', 'k/duration', 'k/dashboard', 'k/report', 'k/snapshot', 'k/dashboard.css'] as $route) {
				self::expect(true, in_array($route, $patterns, true), 'route ' . $route);
			}
			self::expect('Besuche', $kirby->translation('de')->get('kizami.tile.visits'), 'German strings registered as Kirby translations');

			// Options: only kizami.*, both spellings; false is a value, null is "not set".
			$get = fn (array $o, string $k, $v = null) => Options::get($app($o), $k, $v);
			self::expect('new', $get(['kizami.siteId' => 'new'], 'siteId'), 'kizami.* is read');
			self::expect('default', $get(['kennzahlen.kennung' => 'old'], 'siteId', 'default'), 'kennzahlen.* is no longer read');
			self::expect(true, $get(['kizami' => ['active' => true]], 'active', false), 'nested spelling');
			self::expect(false, $get(['kizami.active' => false], 'active', true), 'false is a value, not a fallback');
			self::expect('#123456', $get(['kizami.color' => null], 'color', '#123456'), 'null counts as not set');

			// Language: kizami.language, then the locale, then English.
			self::expect('en', Options::language($app()), 'language default English');
			self::expect('de', Options::language($app(['locale' => 'de_DE.utf8'])), 'language from the locale');
			self::expect('de', Options::language($app(['locale' => [LC_ALL => 'de_DE.utf8']])), 'language from a locale array');
			self::expect('en', Options::language($app(['locale' => 'de_DE.utf8', 'kizami.language' => 'en'])), 'kizami.language wins');
			self::expect('en', Options::language($app(['locale' => 'fr_FR.utf8'])), 'unsupported language falls back to English');

			// Brand: option (string or closure), else the site title.
			self::expect('Example', Options::brand($app()), 'brand defaults to the site title');
			self::expect('Shop', Options::brand($app(['kizami.brand' => 'Shop'])), 'brand string');
			self::expect('Shop 2', Options::brand($app(['kizami.brand' => fn () => 'Shop 2'])), 'brand closure');

			// Access file: site/config/kizami.php with user/hash. kennzahlen.php is ignored.
			$config = $tmp . '/site/config';
			self::expect($config . '/kizami.php', Options::accessFile($app()), 'access file name');
			$dashboard = function (string $user, string $password, array $options) use ($app) {
				$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($user . ':' . $password);
				try {
					return $app($options)->call('k/dashboard')->code();
				} finally {
					unset($_SERVER['HTTP_AUTHORIZATION']);
				}
			};
			file_put_contents($config . '/kennzahlen.php', '<?php return ' . var_export(['benutzer' => 'old', 'hash' => password_hash('old-pw', PASSWORD_DEFAULT)], true) . ';');
			self::expect(404, $dashboard('old', 'old-pw', ['kizami.active' => true]), 'old access file alone: real 404, not used');
			file_put_contents($config . '/kizami.php', '<?php return ' . var_export(['user' => 'new', 'hash' => password_hash('new-pw', PASSWORD_DEFAULT)], true) . ';');
			self::expect(200, $dashboard('new', 'new-pw', ['kizami.active' => true]), 'dashboard with kizami.php');
			self::expect(401, $dashboard('old', 'old-pw', ['kizami.active' => true]), 'old credentials rejected');
			self::expect(404, $dashboard('new', 'new-pw', []), 'without active: real 404');
			$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('new' . ':' . 'new-pw');
			$css = $app(['kizami.active' => true, 'kizami.fonts' => ['sans' => ['name' => 'Karla', 'file' => '/assets/fonts/karla.woff2']]])->call('k/dashboard.css');
			unset($_SERVER['HTTP_AUTHORIZATION']);
			self::expect([200, true], [$css->code(), str_contains($css->body(), '--sans: "Karla"')], 'kizami.fonts ends up in dashboard.css');

			// Removed transition names stay removed.
			self::expect(false, class_exists('Kennzahlen\\Speicher', false), 'no Kennzahlen\\* aliases');
			self::expect(false, function_exists('kennzahlen_verzeichnis'), 'no kennzahlen_*() helpers');
			self::expect(false, method_exists(Sayamaapps\Kizami\Options::class, 'zugangsdatei'), 'no German method names');

			// Storage move, Kirby-free: files are renamed, the hot WAL moves along.
			$storage = $tmp . '/storage-a';
			mkdir($storage . '/kennzahlen', 0700, true);
			$live = self::legacyDatabase($tmp . '/live.sqlite');
			$live->exec('PRAGMA journal_mode = WAL');
			$live->exec('PRAGMA wal_autocheckpoint = 0');
			$live->exec("INSERT INTO ereignisse (id, erstellt, typ, aktion, pfad, sitzung) VALUES (16, '2026-10-06 09:00:00', 'seite', 'aufruf', '/wal', 'h2')");
			// Copy while the connection is open: the copy has the last row only in
			// its -wal, like a site whose last writes were never checkpointed.
			foreach (['', '-wal', '-shm'] as $suffix) {
				if (is_file($tmp . '/live.sqlite' . $suffix)) {
					copy($tmp . '/live.sqlite' . $suffix, $storage . '/kennzahlen/kennzahlen.sqlite' . $suffix);
				}
			}
			self::expect(true, is_file($storage . '/kennzahlen/kennzahlen.sqlite-wal') && filesize($storage . '/kennzahlen/kennzahlen.sqlite-wal') > 0, 'fixture has a non-empty WAL');
			$live->close();
			file_put_contents($storage . '/kennzahlen/geheimnis.txt', date('Y-m-d') . "\nsecret");
			file_put_contents($storage . '/kennzahlen/fehler.log', "old error\n");

			self::expect($storage . '/kizami', Migration::storage($storage), 'migration returns the new directory');
			self::expect(false, is_dir($storage . '/kennzahlen'), 'old directory is gone');
			self::expect(true, is_file($storage . '/kizami/kizami.sqlite'), 'kennzahlen.sqlite → kizami.sqlite');
			self::expect(true, is_file($storage . '/kizami/kizami.sqlite-wal'), '-wal moved with it');
			self::expect(false, file_exists($storage . '/kizami/kennzahlen.sqlite'), 'no old database name left');
			self::expect(date('Y-m-d') . "\nsecret", file_get_contents($storage . '/kizami/secret.txt'), 'geheimnis.txt → secret.txt, same content (same-day hashes stay)');
			self::expect("old error\n", file_get_contents($storage . '/kizami/errors.log'), 'fehler.log → errors.log');
			self::expect(false, file_exists($storage . '/.kizami-migration.lock'), 'no lock file left behind');
			self::expect($storage . '/kizami', Migration::storage($storage), 'second call: nothing to do');

			// Schema migration on first open.
			$store = new Store($storage . '/kizami/kizami.sqlite');
			$db = new SQLite3($storage . '/kizami/kizami.sqlite');
			$tables = [];
			$result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
			while ($row = $result->fetchArray(SQLITE3_NUM)) {
				$tables[] = $row[0];
			}
			self::expect(['kizami_custom_events', 'kizami_days', 'kizami_discarded', 'kizami_events', 'kizami_maintenance'], $tables, 'only English tables left');
			self::expect(7, $db->querySingle('SELECT COUNT(*) FROM kizami_events'), 'every event kept, including the one from the WAL');
			self::expect(1, $db->querySingle("SELECT COUNT(*) FROM kizami_events WHERE id = 16 AND path = '/wal'"), 'WAL row present after the move');
			$mapped = [];
			$result = $db->query('SELECT id, type, action, referrer, device, position, seconds, target FROM kizami_events ORDER BY id');
			while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
				$mapped[$row['id']] = $row;
			}
			self::expect(['page', 'view', '(internal)', 'mobile'], [$mapped[10]['type'], $mapped[10]['action'], $mapped[10]['referrer'], $mapped[10]['device']], 'page view mapped');
			self::expect(['redirect', 'click', 'header', 'anruf'], [$mapped[11]['type'], $mapped[11]['action'], $mapped[11]['position'], $mapped[11]['target']], 'redirect mapped, kopf → header, target name kept');
			self::expect('kontakt', $mapped[12]['position'], 'site-specific positions stay as they are');
			self::expect(['notfound', 'view'], [$mapped[13]['type'], $mapped[13]['action']], 'missing page mapped');
			self::expect(['image', 'click', 'footer', 'tablet'], [$mapped[14]['type'], $mapped[14]['action'], $mapped[14]['position'], $mapped[14]['device']], 'image mapped, fuss → footer');
			self::expect(['duration', 'visible', 'content', 42], [$mapped[15]['type'], $mapped[15]['action'], $mapped[15]['position'], $mapped[15]['seconds']], 'duration mapped, inhalt → content');
			self::expect(7, $db->querySingle("SELECT views FROM kizami_days WHERE day = '2020-01-01'"), 'daily totals kept');
			self::expect(4, $db->querySingle("SELECT hits FROM kizami_discarded WHERE day = '2026-10-06'"), 'discarded counter kept');
			self::expect('2026-10-07', $db->querySingle('SELECT day FROM kizami_maintenance WHERE id = 1'), 'maintenance marker kept');
			self::expect(1, $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_kizami_events_created_at'"), 'indexes created');
			self::expect(0, $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'idx_ereignisse%'"), 'old indexes gone with the old table');
			$db->exec("INSERT INTO kizami_events (type, action, path) VALUES ('page', 'view', '/next')");
			self::expect(true, $db->querySingle('SELECT MAX(id) FROM kizami_events') > 16, 'new ids continue after the migrated ones');
			$db->close();
			$store = $store->atTime(new DateTimeImmutable('2026-10-06 23:00:00', new DateTimeZone('Europe/Berlin')));
			self::expect(2, $store->visits(1), 'migrated data is readable through Store (two day identifiers)');
			self::expect(1, $store->clicks(1, 0, 'anruf'), 'migrated redirect counted for its target');
			$store = null;
			new Store($storage . '/kizami/kizami.sqlite');
			self::expect(true, true, 'opening the migrated database again is a no-op');

			// Oldest pre-1.0 state: no device, position or seconds columns yet.
			$old = $tmp . '/oldest.sqlite';
			self::legacyDatabase($old, false)->close();
			new Store($old);
			$db = new SQLite3($old);
			self::expect(['page', 'view', '', '', 0, 'google.com'], array_values($db->querySingle('SELECT type, action, device, position, seconds, referrer FROM kizami_events WHERE id = 3', true)), 'missing columns get their defaults');
			self::expect(0, $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('ereignisse', 'kennzahlen_tage')"), 'oldest state: legacy tables gone');
			$db->close();

			// A migration that fails leaves the old tables untouched.
			$broken = $tmp . '/broken.sqlite';
			$db = self::legacyDatabase($broken);
			$db->exec('CREATE TABLE kizami_days (day TEXT)'); // blocks the CREATE TABLE IF NOT EXISTS → INSERT with unknown columns
			$db->close();
			$error = null;
			try {
				new Store($broken);
			} catch (RuntimeException $e) {
				$error = $e->getMessage();
			}
			self::expect(true, is_string($error) && str_contains($error, 'migration of the pre-1.0 schema failed'), 'failed migration throws a RuntimeException');
			$db = new SQLite3($broken);
			self::expect([6, 0], [$db->querySingle('SELECT COUNT(*) FROM ereignisse'), $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name = 'kizami_events'")], 'rollback: old events untouched, no half-built new table');
			$db->close();

			// Both directories present: nothing is moved, the new one is used.
			$storage = $tmp . '/storage-b';
			mkdir($storage . '/kennzahlen', 0700, true);
			mkdir($storage . '/kizami', 0700, true);
			file_put_contents($storage . '/kennzahlen/kennzahlen.sqlite', 'x');
			$log = $tmp . '/php-error.log';
			$previousLog = ini_set('error_log', $log);
			self::expect($storage . '/kizami', Migration::storage($storage), 'both present: new directory used');
			ini_set('error_log', (string)$previousLog);
			self::expect(true, is_file($storage . '/kennzahlen/kennzahlen.sqlite'), 'both present: old data left alone');
			self::expect(true, str_contains((string)@file_get_contents($log), 'not migrating'), 'both present: logged');

			// Through Kirby: \Sayamaapps\Kizami\Runtime::directory() runs the move. The dashboard calls
			// above already created storage/kizami/ — start from the pre-1.0 state.
			self::removeTree($tmp . '/storage/kizami');
			mkdir($tmp . '/storage/kennzahlen');
			self::legacyDatabase($tmp . '/storage/kennzahlen/kennzahlen.sqlite')->close();
			$app();
			self::expect($tmp . '/storage/kizami', \Sayamaapps\Kizami\Runtime::directory(), '\Sayamaapps\Kizami\Runtime::directory() moves the legacy directory');
			self::expect([true, false], [is_file($tmp . '/storage/kizami/kizami.sqlite'), is_dir($tmp . '/storage/kennzahlen')], '… with the database renamed');

			// Old copy next to the package: separate process, because Kirby loads each plugin file only once.
			$tmp2 = $tmp . '/twice';
			mkdir($tmp2);
			self::fixtureSite($tmp2);
			mkdir($tmp2 . '/site/plugins/kennzahlen');
			file_put_contents($tmp2 . '/site/plugins/kennzahlen/index.php', "<?php\nnamespace Kennzahlen { final class Erfassung {} }\nnamespace { Kirby\\Cms\\App::plugin('kennzahlen/kern', []); }\n");
			symlink($base, $tmp2 . '/site/plugins/kizami');
			$script = $tmp2 . '/probe.php';
			file_put_contents($script, '<?php require ' . var_export($base . '/vendor/autoload.php', true) . '; ini_set("error_log", ' . var_export($tmp2 . '/log.txt', true) . ');'
				. '$b = ' . var_export($tmp2, true) . '; $k = new Kirby\Cms\App(["roots" => ["index" => "$b/public", "base" => $b, "content" => "$b/content", "site" => "$b/site", "storage" => "$b/storage"], "options" => ["kizami.active" => true]]);'
				. 'echo json_encode([$k->plugin("sayamaapps/kizami") !== null, $k->plugin("kennzahlen/kern") !== null, in_array("k/dashboard", array_column($k->extensions("routes"), "pattern"), true)]);');
			$output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
			self::expect('[false,true,false]', trim((string)$output), 'old copy next to it: the package registers nothing');
			self::expect(true, str_contains((string)@file_get_contents($tmp2 . '/log.txt'), 'site/plugins/kennzahlen/ still exists'), '… and says so in the error log');

		} catch (Throwable $e) {
			throw $e;
		} finally {
			self::removeTree($tmp);
		}
		$this->assertFalse((bool)$failed, 'no failure');
	}
}
