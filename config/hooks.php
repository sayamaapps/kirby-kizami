<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\Hooks;

// The hooks themselves are in src/Hooks.php.
return [
	'route:after' => fn ($route, $path, $method, $result, $final) => Hooks::routeAfter(App::instance(), $route, $path, $method, $result, $final),
];
