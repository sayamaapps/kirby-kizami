<?php

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases and
 * function wrappers only; no logic here.
 */

// The 1.x class names (namespace Kizami\) are aliases of the new classes. An alias
// is created when its old name is used for the first time, so a request loads
// only the classes it needs.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'Kizami\\') === false) {
		return;
	}

	$current = 'Sayamaapps\\Kizami\\' . substr($class, strlen('Kizami\\'));
	if (class_exists($current) === true) {
		class_alias($current, $class);
	}
});

// The exception classes are aliased at once: a catch block does not run the
// autoloader, so a lazy alias would never match in 1.x code that catches
// Kizami\SetupError or Kizami\ReportError.
class_alias(Sayamaapps\Kizami\SetupError::class, 'Kizami\\SetupError');
class_alias(Sayamaapps\Kizami\ReportError::class, 'Kizami\\ReportError');

if (Kirby\Cms\Helpers::hasOverride('kizami_option') === false) {
	function kizami_option(string $key, mixed $default = null): mixed
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_option() is deprecated, use Sayamaapps\\Kizami\\Runtime::option()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::option(Kirby\Cms\App::instance(), $key, $default);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_store') === false) {
	function kizami_store(): \Sayamaapps\Kizami\Store
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_store() is deprecated, use Sayamaapps\\Kizami\\Runtime::store()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::store(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_own_hosts') === false) {
	function kizami_own_hosts(): array
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_own_hosts() is deprecated, use Sayamaapps\\Kizami\\Runtime::ownHosts()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::ownHosts(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_directory') === false) {
	function kizami_directory(): string
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_directory() is deprecated, use Sayamaapps\\Kizami\\Runtime::directory()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::directory(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_rotate_secret') === false) {
	function kizami_rotate_secret(): void
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_rotate_secret() is deprecated, use Sayamaapps\\Kizami\\Runtime::rotateSecret()',
			'kizami-function'
		);

		Sayamaapps\Kizami\Runtime::rotateSecret(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_log_error') === false) {
	function kizami_log_error(\Throwable $e): void
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_log_error() is deprecated, use Sayamaapps\\Kizami\\Runtime::logError()',
			'kizami-function'
		);

		Sayamaapps\Kizami\Runtime::logError(Kirby\Cms\App::instance(), $e);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_write') === false) {
	function kizami_write(array|callable $row, callable|null $counts = null): void
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_write() is deprecated, use Sayamaapps\\Kizami\\Runtime::write()',
			'kizami-function'
		);

		Sayamaapps\Kizami\Runtime::write(Kirby\Cms\App::instance(), $row, $counts);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_campaign') === false) {
	function kizami_campaign(): string
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_campaign() is deprecated, use Sayamaapps\\Kizami\\Runtime::campaign()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::campaign(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_beacon_path') === false) {
	function kizami_beacon_path(): string|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_beacon_path() is deprecated, use Sayamaapps\\Kizami\\Runtime::beaconPath()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::beaconPath(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_find_page') === false) {
	function kizami_find_page(mixed $path): \Kirby\Cms\Page|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_find_page() is deprecated, use Sayamaapps\\Kizami\\Runtime::findPage()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::findPage(Kirby\Cms\App::instance(), $path);
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

		Sayamaapps\Kizami\Runtime::event(Kirby\Cms\App::instance(), $key, $page, $detail);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_dashboard_refused') === false) {
	function kizami_dashboard_refused(): \Kirby\Http\Response|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_dashboard_refused() is deprecated, use Sayamaapps\\Kizami\\Runtime::dashboardRefused()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::dashboardRefused(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_access_denied') === false) {
	function kizami_access_denied(): \Kirby\Http\Response|null
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_access_denied() is deprecated, use Sayamaapps\\Kizami\\Runtime::accessDenied()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::accessDenied(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_login_unavailable') === false) {
	function kizami_login_unavailable(string $text): \Kirby\Http\Response
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_login_unavailable() is deprecated, use Sayamaapps\\Kizami\\Runtime::loginUnavailable()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::loginUnavailable(Kirby\Cms\App::instance(), $text);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_deny') === false) {
	function kizami_deny(): \Kirby\Http\Response
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_deny() is deprecated, use Sayamaapps\\Kizami\\Runtime::deny()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::deny(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_not_found') === false) {
	function kizami_not_found(): \Kirby\Http\Response
	{
		Kirby\Cms\Helpers::deprecated(
			'kizami_not_found() is deprecated, use Sayamaapps\\Kizami\\Runtime::notFound()',
			'kizami-function'
		);

		return Sayamaapps\Kizami\Runtime::notFound(Kirby\Cms\App::instance());
	}
}
