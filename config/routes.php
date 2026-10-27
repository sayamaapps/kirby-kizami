<?php

use Kirby\Cms\App;
use Sayamaapps\Kizami\Routes;

// The routes themselves are in src/Routes.php. Routes are a closure, not an
// array, so the redirects can depend on the options at resolution time.
return fn () => Routes::all(App::instance());
