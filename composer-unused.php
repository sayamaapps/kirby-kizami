<?php

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// Requirements without PHP symbols in the code: the PHP version, the mbstring
// extension (mb_* calls are not seen by the tool) and the installer that places
// the plugin in site/plugins.
return static function (Configuration $configuration): Configuration {
	return $configuration
		->addNamedFilter(NamedFilter::fromString('php'))
		->addNamedFilter(NamedFilter::fromString('ext-mbstring'))
		->addNamedFilter(NamedFilter::fromString('getkirby/composer-installer'));
};
