<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Request;
use Waimart\Core\Response;

/** Liveness check for monitoring. Reveals no configuration details. */
final class HealthController
{
    public function show(Request $req): Response
    {
        try {
            App::db()->value('SELECT 1');
            return Response::json(['status' => 'ok'])->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return Response::json(['status' => 'unavailable'], 503)->withHeader('Cache-Control', 'no-store');
        }
    }
}
