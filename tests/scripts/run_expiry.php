<?php

declare(strict_types=1);

/** Worker for ConcurrencyTest: waits for a shared start time, runs the expiry service, prints stats JSON. */

putenv('WAIMART_ENV=testing');
require dirname(__DIR__, 2) . '/bootstrap.php';

[, $startAt] = $argv;
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
echo json_encode((new Waimart\Services\ExpiryService())->run()), "\n";
