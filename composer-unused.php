<?php

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// Requirements the tool cannot see in the code: the PHP version, the mbstring
// and ctype extensions (mb_* and ctype_* calls are not seen by the tool), the
// SQLite3 extension (class usage) and the installer that places the plugin in
// site/plugins.
return static function (Configuration $configuration): Configuration {
	return $configuration
		->addNamedFilter(NamedFilter::fromString('php'))
		->addNamedFilter(NamedFilter::fromString('ext-mbstring'))
		->addNamedFilter(NamedFilter::fromString('ext-ctype'))
		->addNamedFilter(NamedFilter::fromString('getkirby/composer-installer'))
		// SQLite3 is a class of the extension; the tool does not see it as a used symbol.
		->addNamedFilter(NamedFilter::fromString('ext-sqlite3'));
};
