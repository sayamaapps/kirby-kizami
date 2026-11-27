<?php

namespace Sayamaapps\Kizami;

/**
 * The link filter of the redirects (Capture::countsLinkClick()) as the check
 * Runtime::write() takes (since 3.1.0). An object instead of a closure, so a
 * click that meets a locked database can go to the spool with what the check
 * still needs: a confirmed user action counts at once; without one, the click
 * is decided when the spool is read, against the page views then in the
 * database (Store::readSpool()).
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kizami
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class LinkFilter
{
	public function __construct(public readonly bool $userActivated)
	{
	}

	public function __invoke(Store $store, string $session, \DateTimeImmutable $now): bool
	{
		return Capture::countsLinkClick($this->userActivated, fn () => $store->hasPageView($session, $now));
	}

	/** What a spooled click still needs: '' (counts) or 'pageview' (see Spool::add()). */
	public function spoolCheck(): string
	{
		return $this->userActivated ? '' : 'pageview';
	}
}
