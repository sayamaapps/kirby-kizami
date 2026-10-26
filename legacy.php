<?php

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases and
 * function wrappers only; no logic here.
 */

class_alias(\Sayamaapps\Kizami\Access::class, 'Kizami\\Access');
class_alias(\Sayamaapps\Kizami\Analysis::class, 'Kizami\\Analysis');
class_alias(\Sayamaapps\Kizami\Capture::class, 'Kizami\\Capture');
class_alias(\Sayamaapps\Kizami\Chart::class, 'Kizami\\Chart');
class_alias(\Sayamaapps\Kizami\Comparison::class, 'Kizami\\Comparison');
class_alias(\Sayamaapps\Kizami\Events::class, 'Kizami\\Events');
class_alias(\Sayamaapps\Kizami\Fonts::class, 'Kizami\\Fonts');
class_alias(\Sayamaapps\Kizami\Gaps::class, 'Kizami\\Gaps');
class_alias(\Sayamaapps\Kizami\I18n::class, 'Kizami\\I18n');
class_alias(\Sayamaapps\Kizami\Lockout::class, 'Kizami\\Lockout');
class_alias(\Sayamaapps\Kizami\Migration::class, 'Kizami\\Migration');
class_alias(\Sayamaapps\Kizami\Names::class, 'Kizami\\Names');
class_alias(\Sayamaapps\Kizami\Options::class, 'Kizami\\Options');
class_alias(\Sayamaapps\Kizami\Redirect::class, 'Kizami\\Redirect');
class_alias(\Sayamaapps\Kizami\Report::class, 'Kizami\\Report');
class_alias(\Sayamaapps\Kizami\ReportError::class, 'Kizami\\ReportError');
class_alias(\Sayamaapps\Kizami\ReportService::class, 'Kizami\\ReportService');
class_alias(\Sayamaapps\Kizami\Secret::class, 'Kizami\\Secret');
class_alias(\Sayamaapps\Kizami\Setup::class, 'Kizami\\Setup');
class_alias(\Sayamaapps\Kizami\SetupError::class, 'Kizami\\SetupError');
class_alias(\Sayamaapps\Kizami\SnapshotResponse::class, 'Kizami\\SnapshotResponse');
class_alias(\Sayamaapps\Kizami\SnapshotService::class, 'Kizami\\SnapshotService');
class_alias(\Sayamaapps\Kizami\Store::class, 'Kizami\\Store');

if (Kirby\Cms\Helpers::hasOverride('kizami_option') === false) {
	function kizami_option(string $key, mixed $default = null): mixed
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_option() is deprecated, use Sayamaapps\\Kizami\\Runtime::option()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::option($key, $default);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_store') === false) {
	function kizami_store(): \Sayamaapps\Kizami\Store
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_store() is deprecated, use Sayamaapps\\Kizami\\Runtime::store()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::store();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_own_hosts') === false) {
	function kizami_own_hosts(): array
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_own_hosts() is deprecated, use Sayamaapps\\Kizami\\Runtime::ownHosts()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::ownHosts();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_directory') === false) {
	function kizami_directory(): string
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_directory() is deprecated, use Sayamaapps\\Kizami\\Runtime::directory()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::directory();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_rotate_secret') === false) {
	function kizami_rotate_secret(): void
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_rotate_secret() is deprecated, use Sayamaapps\\Kizami\\Runtime::rotateSecret()',
			'kizami-function'
		);

		Sayamaapps\Kizami\Runtime::rotateSecret();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_log_error') === false) {
	function kizami_log_error(\Throwable $e): void
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_log_error() is deprecated, use Sayamaapps\\Kizami\\Runtime::logError()',
			'kizami-function'
		);

		Sayamaapps\Kizami\Runtime::logError($e);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_write') === false) {
	function kizami_write(array|callable $row, callable|null $counts = null): void
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_write() is deprecated, use Sayamaapps\\Kizami\\Runtime::write()',
			'kizami-function'
		);

		Sayamaapps\Kizami\Runtime::write($row, $counts);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_campaign') === false) {
	function kizami_campaign(): string
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_campaign() is deprecated, use Sayamaapps\\Kizami\\Runtime::campaign()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::campaign();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_beacon_path') === false) {
	function kizami_beacon_path(): string|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_beacon_path() is deprecated, use Sayamaapps\\Kizami\\Runtime::beaconPath()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::beaconPath();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_find_page') === false) {
	function kizami_find_page(mixed $path): \Kirby\Cms\Page|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_find_page() is deprecated, use Sayamaapps\\Kizami\\Runtime::findPage()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::findPage($path);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_page_path') === false) {
	function kizami_page_path(mixed $page): string|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_page_path() is deprecated, use Sayamaapps\\Kizami\\Runtime::pagePath()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::pagePath($page);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_event') === false) {
	function kizami_event(mixed $key, mixed $page, mixed $detail = null): void
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_event() is deprecated, use Sayamaapps\\Kizami\\Runtime::event()',
			'kizami-function'
		);

		Sayamaapps\Kizami\Runtime::event($key, $page, $detail);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_dashboard_refused') === false) {
	function kizami_dashboard_refused(): \Kirby\Http\Response|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_dashboard_refused() is deprecated, use Sayamaapps\\Kizami\\Runtime::dashboardRefused()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::dashboardRefused();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_access_denied') === false) {
	function kizami_access_denied(): \Kirby\Http\Response|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_access_denied() is deprecated, use Sayamaapps\\Kizami\\Runtime::accessDenied()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::accessDenied();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_login_unavailable') === false) {
	function kizami_login_unavailable(string $text): \Kirby\Http\Response
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_login_unavailable() is deprecated, use Sayamaapps\\Kizami\\Runtime::loginUnavailable()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::loginUnavailable($text);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_deny') === false) {
	function kizami_deny(): \Kirby\Http\Response
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_deny() is deprecated, use Sayamaapps\\Kizami\\Runtime::deny()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::deny();
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_not_found') === false) {
	function kizami_not_found(): \Kirby\Http\Response
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_not_found() is deprecated, use Sayamaapps\\Kizami\\Runtime::notFound()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::notFound();
	}
}
