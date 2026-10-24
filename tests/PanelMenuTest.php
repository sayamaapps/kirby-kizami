<?php
/**
 * Panel menu entry (since 1.1.0): registered as a plain link area, shown
 * only while kizami.active is true, labelled from the translations.
 * Runs on Kirby 5 and 6: the plugin's area definition is the same, only the
 * Panel classes that turn it into a menu entry differ.
 *   php tests/PanelMenuTest.php
 */
$base = dirname(__DIR__);
if (!is_file($base . '/vendor/autoload.php')) { fwrite(STDERR, "Composer dependencies missing; run composer install first\n"); exit(1); }
require $base . '/vendor/autoload.php';

use Kirby\Panel\Area;
use Kirby\Panel\Areas;
use Kirby\Panel\Menu;
use Kirby\Panel\Panel;
use Kirby\Toolkit\I18n;

$n = 0;
function expect($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $n++;
}

$tmp = sys_get_temp_dir() . '/kizami-panel-' . bin2hex(random_bytes(6));
$failed = false;
try {
    foreach (['public', 'site/plugins', 'site/config', 'content', 'storage'] as $dir) {
        mkdir($tmp . '/' . $dir, 0700, true);
    }
    symlink($base, $tmp . '/site/plugins/kizami');
    $app = fn (array $options) => new Kirby\Cms\App([
        'roots' => ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
            'site' => $tmp . '/site', 'storage' => $tmp . '/storage'],
        'options' => ['url' => 'https://example.invalid'] + $options,
    ]);
    // The menu entry Kirby would build for the area: link, target, icon and
    // text, or false while the area is hidden. Kirby 5 normalizes the area
    // with Panel::area() and builds the entry with Menu::entry(); Kirby 6
    // replaced both with Areas::area() (an Area object) and Menu::item().
    $entry = function (Kirby\Cms\App $kirby, string $language = 'en') {
        I18n::$locale = $language;
        // Loader::areas() resolves the area closures like the Panel does.
        $area = $kirby->load()->areas()['kizami'] ?? null;
        if ($area === null) { return null; }
        if (method_exists(Panel::class, 'area')) {
            $definition = Panel::area('kizami', $area);
            return (new Menu(['kizami' => $definition], [], null))->entry($definition);
        }
        $item = (new Menu(new Areas(['kizami' => Areas::area('kizami', $area)]), ['kizami']))->item('kizami');
        if ($item === null) { return false; }
        $props = $item->props();
        return ['link' => $props['link'], 'target' => $props['target'], 'icon' => $props['icon'], 'text' => $props['text']];
    };

    $kirby = $app(['kizami.active' => true]);
    expect(true, isset($kirby->extensions('areas')['kizami']), 'area registered');
    $e = $entry($kirby);
    expect('https://example.invalid/k/dashboard', $e['link'] ?? null, 'links to the dashboard');
    expect(['_blank', 'chart'], [$e['target'] ?? null, $e['icon'] ?? null], 'new tab, chart icon');
    expect('Statistics', $e['text'] ?? null, 'English label');
    expect('Kennzahlen', $entry($kirby, 'de')['text'] ?? null, 'German label for a German Panel user');
    expect(false, $entry($app(['kizami.active' => false])), 'inactive: no menu entry');
    expect(false, $entry($app([])), 'not configured: no menu entry');
    expect(false, $entry($app(['kizami.active' => 'yes'])), 'only true switches it on');

    // Kirby 6 ignores keys its Area class does not know instead of failing,
    // so a misspelled key in the definition would silently get lost.
    if (class_exists(Area::class)) {
        $known = array_map(fn (ReflectionParameter $parameter) => $parameter->getName(), (new ReflectionClass(Area::class))->getConstructor()->getParameters());
        $definition = $kirby->load()->areas()['kizami'];
        expect([], array_values(array_diff(array_keys($definition), $known)), 'every key of the area definition is one Kirby 6 knows');
        expect(Area::class, Areas::area('kizami', $definition)::class, 'the definition makes an Area');
    }

    echo "Kizami Panel menu: all $n checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Kizami Panel menu: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $failed = true;
} finally {
    @unlink($tmp . '/site/plugins/kizami');
    exec('rm -rf ' . escapeshellarg($tmp));
}
exit($failed ? 1 : 0);
