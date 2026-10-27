<?php

namespace Sayamaapps\Kizami;

/**
 * Checks the one setup rule Kizami cannot work around: the storage root must
 * exist as a setting and must lie outside the web root. A web server without
 * a deny rule serves kizami.sqlite and secret.txt from a directory below the
 * document root as plain files, no login involved.
 */
class Setup
{
	/**
	 * Absolute path with symlinks resolved as far as it exists. The storage
	 * directory may not exist yet: resolve the nearest existing ancestor and
	 * append the rest.
	 */
	private static function normalise(string $path): string
	{
		$path = rtrim(str_replace('\\', '/', $path), '/');
		$rest = [];
		$probe = $path;
		while ($probe !== '' && ($real = @realpath($probe)) === false) {
			$rest[] = basename($probe);
			$parent = dirname($probe);
			if ($parent === $probe) {
				break;
			}
			$probe = $parent;
		}
		$base = ($real ?? false) !== false ? rtrim(str_replace('\\', '/', $real), '/') : $probe;
		return $base . ($rest === [] ? '' : '/' . implode('/', array_reverse($rest)));
	}

	/**
	 * Returns the storage root to use or throws SetupError.
	 *
	 * @param $storage Kirby's `storage` root (Kirby defines none)
	 * @param $index Kirby's `index` root, the document root
	 */
	public static function storageRoot(string|null $storage, string|null $index): string
	{
		if ($storage === null || trim($storage) === '') {
			throw new SetupError('Kizami: Kirby root "storage" is not set. Define it in index.php outside the web root (see README, Installation).');
		}

		if ($index !== null && trim($index) !== '') {
			$s = self::normalise($storage);
			$i = self::normalise($index);
			// Equal counts as inside; a storage root that contains the index root is fine.
			if (str_starts_with($s . '/', rtrim($i, '/') . '/')) {
				throw new SetupError(
					'Kizami: storage root ' . $s . ' lies inside the web root ' . $i . '. Move it outside; '
					. 'a web server would serve the database and the daily key as files.'
				);
			}
			// The data directory below the root is written to as well. A link there
			// (storage/kizami pointing into the web root) passes the check above.
			foreach ([Migration::DIRECTORY, Migration::LEGACY_DIRECTORY] as $sub) {
				$data = self::normalise($s . '/' . $sub);
				if (str_starts_with($data . '/', rtrim($i, '/') . '/')) {
					throw new SetupError(
						'Kizami: data directory ' . $data . ' lies inside the web root ' . $i . '. Remove the link or move it outside; '
						. 'a web server would serve the database and the daily key as files.'
					);
				}
			}
		}

		return $storage;
	}
}
