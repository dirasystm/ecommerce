<?php

declare(strict_types=1);

use Waimart\Core\Kernel;
use Waimart\Core\Request;
use Waimart\Core\Router;

require dirname(__DIR__) . '/bootstrap.php';

$router = new Router();
require dirname(__DIR__) . '/routes/web.php';

$request = Request::fromGlobals((string) config('app.base_path', ''));
(new Kernel($router))->handle($request)->send();
