<?php

namespace Sayamaapps\Kizami;

use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * The Kirby hooks of the plugin. config/hooks.php wires them in.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Hooks
{
	/**
	 * After every resolved route. Only real, rendered pages count as 'page' — not
	 * assets, not the Panel. A real 404 counts separately as 'notfound'.
	 * Capturing must NEVER damage the page: Runtime::write() doesn't throw.
	 *
	 * A real 404 has NO page object here yet: the page route returns `null` for a
	 * path it can't resolve, and App::io() only builds the error page afterwards
	 * from a NotFoundException. Hence the special case via `$final` (last route
	 * pass) and an empty `$result`, instead of `$result->isErrorPage()`, which
	 * would only catch someone calling the content page configured as error page
	 * by its real path.
	 */
	public static function routeAfter(App $kirby, mixed $route, mixed $path, mixed $method, mixed $result, mixed $final): void
	{
		try {
			if ($method !== 'GET') {
				return;
			}
			$address = Capture::cleanPath('/' . ltrim((string)$path, '/'));
			// 404: the page route returns nothing, io() builds the error page later.
			if ($final && empty($result)) {
				Runtime::write($kirby, fn () => ['type' => 'notfound', 'action' => 'view', 'path' => $address]);

				return;
			}
			if (!($result instanceof Page) || $result->isErrorPage()) {
				return;
			}
			Runtime::write($kirby, fn () => [
				'type' => 'page',
				'action' => 'view',
				'path' => $address,
				'referrer' => Capture::cleanReferrer($kirby->request()->header('Referer'), Runtime::ownHosts($kirby)),
				'utm_source' => Capture::cleanId($kirby->request()->get('utm_source')),
				'utm_medium' => Capture::cleanId($kirby->request()->get('utm_medium')),
				'utm_campaign' => Runtime::campaign($kirby),
			]);
		} catch (\Throwable $e) {
			error_log('Kizami: ' . $e->getMessage());
		}
	}
}
