<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Kirby's Whoops handler would stay registered after every App is created and
// flag each test as risky. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
