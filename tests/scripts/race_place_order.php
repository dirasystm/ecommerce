<?php

declare(strict_types=1);

/**
 * Worker for ConcurrencyTest: waits until a shared start time, then places an order.
 * Usage: php race_place_order.php <cartToken> <idempotencyKey> <phone> <startAtMicrotime>
 * Prints one JSON line: {"ok":bool,"created":bool,"order":string|null,"error":string|null}
 */

putenv('WAIMART_ENV=testing');
require dirname(__DIR__, 2) . '/bootstrap.php';

use Waimart\Core\Settings;
use Waimart\Services\CartService;
use Waimart\Services\OrderService;

[, $cartToken, $key, $phone, $startAt] = $argv;
$_SESSION = [];

// Busy-wait to the shared start time so all workers hit the database together.
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $r = (new OrderService())->place(new CartService(null, $cartToken), [
        'customer_name' => 'Racer', 'customer_phone' => $phone, 'fulfilment_method' => 'delivery',
        'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Race Track Rd', 'payment_method' => 'cod',
    ], null, $key, '127.0.0.1');
    echo json_encode(['ok' => true, 'created' => $r['created'], 'order' => $r['order']['order_number'], 'error' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'created' => false, 'order' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
