<?php

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases and
 * function wrappers only; no logic here. Nothing in this file reports a
 * deprecation at run time: the 1.x names are marked @deprecated in the
 * DocBlocks and listed in the README.
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
	/** @deprecated Use Sayamaapps\Kizami\Runtime::option(). Removal planned for 2.0. */
	function kizami_option(string $key, mixed $default = null): mixed
	{
		return Sayamaapps\Kizami\Runtime::option(Kirby\Cms\App::instance(), $key, $default);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_store') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::store(). Removal planned for 2.0. */
	function kizami_store(): \Sayamaapps\Kizami\Store
	{
		return Sayamaapps\Kizami\Runtime::store(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_own_hosts') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::ownHosts(). Removal planned for 2.0. */
	function kizami_own_hosts(): array
	{
		return Sayamaapps\Kizami\Runtime::ownHosts(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_directory') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::directory(). Removal planned for 2.0. */
	function kizami_directory(): string
	{
		return Sayamaapps\Kizami\Runtime::directory(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_rotate_secret') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::rotateSecret(). Removal planned for 2.0. */
	function kizami_rotate_secret(): void
	{
		Sayamaapps\Kizami\Runtime::rotateSecret(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_log_error') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::logError(). Removal planned for 2.0. */
	function kizami_log_error(\Throwable $e): void
	{
		Sayamaapps\Kizami\Runtime::logError(Kirby\Cms\App::instance(), $e);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_write') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::write(). Removal planned for 2.0. */
	function kizami_write(array|callable $row, callable|null $counts = null): void
	{
		Sayamaapps\Kizami\Runtime::write(Kirby\Cms\App::instance(), $row, $counts);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_campaign') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::campaign(). Removal planned for 2.0. */
	function kizami_campaign(): string
	{
		return Sayamaapps\Kizami\Runtime::campaign(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_beacon_path') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::beaconPath(). Removal planned for 2.0. */
	function kizami_beacon_path(): string|null
	{
		return Sayamaapps\Kizami\Runtime::beaconPath(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_find_page') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::findPage(). Removal planned for 2.0. */
	function kizami_find_page(mixed $path): \Kirby\Cms\Page|null
	{
		return Sayamaapps\Kizami\Runtime::findPage(Kirby\Cms\App::instance(), $path);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_page_path') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::pagePath(). Removal planned for 2.0. */
	function kizami_page_path(mixed $page): string|null
	{
		return Sayamaapps\Kizami\Runtime::pagePath($page);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_event') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::event(). Removal planned for 2.0. */
	function kizami_event(mixed $key, mixed $page, mixed $detail = null): void
	{
		Sayamaapps\Kizami\Runtime::event(Kirby\Cms\App::instance(), $key, $page, $detail);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_dashboard_refused') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::dashboardRefused(). Removal planned for 2.0. */
	function kizami_dashboard_refused(): \Kirby\Http\Response|null
	{
		return Sayamaapps\Kizami\Runtime::dashboardRefused(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_access_denied') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::accessDenied(). Removal planned for 2.0. */
	function kizami_access_denied(): \Kirby\Http\Response|null
	{
		return Sayamaapps\Kizami\Runtime::accessDenied(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_login_unavailable') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::loginUnavailable(). Removal planned for 2.0. */
	function kizami_login_unavailable(string $text): \Kirby\Http\Response
	{
		return Sayamaapps\Kizami\Runtime::loginUnavailable(Kirby\Cms\App::instance(), $text);
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_deny') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::deny(). Removal planned for 2.0. */
	function kizami_deny(): \Kirby\Http\Response
	{
		return Sayamaapps\Kizami\Runtime::deny(Kirby\Cms\App::instance());
	}
}

if (Kirby\Cms\Helpers::hasOverride('kizami_not_found') === false) {
	/** @deprecated Use Sayamaapps\Kizami\Runtime::notFound(). Removal planned for 2.0. */
	function kizami_not_found(): \Kirby\Http\Response
	{
		return Sayamaapps\Kizami\Runtime::notFound(Kirby\Cms\App::instance());
	}
}
