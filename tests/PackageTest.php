<?php

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * What the installed package ships, taken from a real `git archive HEAD`, the
 * same filter Composer dist and ZIP downloads use: the German privacy text is
 * in the archive and every relative link in a shipped Markdown file resolves
 * to a file or folder in the archive. Skipped outside a git checkout.
 */
#[CoversNothing]
final class PackageTest extends TestCase
{
	/** @var list<string>|null */
	private static array|null $archive = null;

	/** @return list<string> paths in the archive, folders without the trailing slash */
	private function archive(): array
	{
		if (self::$archive !== null) {
			return self::$archive;
		}
		$root = dirname(__DIR__);
		if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
			$this->markTestSkipped('not a git checkout');
		}
		// PharData only opens files that end in .tar.
		$base = tempnam(sys_get_temp_dir(), 'kizami-archive-');
		if ($base === false) {
			$this->fail('no temporary file');
		}
		$tar = $base . '.tar';
		try {
			exec('git -C ' . escapeshellarg($root) . ' archive --format=tar --output=' . escapeshellarg($tar) . ' HEAD 2>&1', $output, $status);
			if ($status !== 0) {
				$this->markTestSkipped('git archive is not available: ' . implode(' ', $output));
			}
			$paths = [];
			foreach (new RecursiveIteratorIterator(new PharData($tar), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
				$paths[] = rtrim(substr((string)$entry->getPathname(), strlen('phar://' . $tar . '/')), '/');
			}
		} finally {
			@unlink($tar);
			@unlink($base);
		}
		return self::$archive = $paths;
	}

	/** @return list<string> shipped Markdown files */
	private function markdownFiles(): array
	{
		return array_values(array_filter($this->archive(), fn (string $path) => str_ends_with($path, '.md')));
	}

	public function testGermanPrivacyTextIsShippedInThePackage(): void
	{
		Assert::assertContains('docs/DATENSCHUTZ.md', $this->archive(), 'README.md and PRIVACY.md link to it');
		Assert::assertContains('PRIVACY.md', $this->archive());
	}

	public function testRelativeLinksInShippedMarkdownResolveToShippedFiles(): void
	{
		$archive = $this->archive();
		$docs = $this->markdownFiles();
		Assert::assertContains('README.md', $docs);
		foreach ($docs as $doc) {
			preg_match_all('/\]\(([^)\s]+)/', (string)file_get_contents(dirname(__DIR__) . '/' . $doc), $matches);
			foreach ($matches[1] as $link) {
				if (preg_match('~^([a-z][a-z0-9+.-]*:|#|//)~i', $link)) {
					continue;
				}
				$target = [];
				foreach (explode('/', dirname($doc) . '/' . explode('#', $link)[0]) as $segment) {
					if ($segment === '..') {
						array_pop($target);
					} elseif ($segment !== '' && $segment !== '.') {
						$target[] = $segment;
					}
				}
				$target = implode('/', $target);
				Assert::assertContains($target, $archive, "$doc links to $link, which is not in the dist archive ($target)");
			}
		}
	}
}
