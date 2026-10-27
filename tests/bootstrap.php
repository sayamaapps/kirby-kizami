<?php

use Kirby\Filesystem\F;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The plugin's classes come from the class map, as in production.
F::loadClasses(require dirname(__DIR__) . '/autoload.php', dirname(__DIR__));

// Kirby's Whoops handler would stay registered after every App is created and
// flag each test as risky. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
