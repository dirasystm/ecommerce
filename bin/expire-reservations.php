<?php

declare(strict_types=1);

/**
 * Releases expired stock reservations (C8). Schedule every minute:
 *   Windows (XAMPP):  see docs/OPERATIONS.md — Task Scheduler
 *   Linux (cron):     * * * * * php /path/to/ecommerce/bin/expire-reservations.php >> /path/to/storage/logs/expiry.log 2>&1
 * Safe to run repeatedly or concurrently. Usage: php bin/expire-reservations.php [--limit=200] [--quiet]
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Services\ExpiryService;

$opts = getopt('', ['limit::', 'quiet']);
$stats = (new ExpiryService())->run((int) ($opts['limit'] ?? 200));

if ($stats['locked']) {
    isset($opts['quiet']) || print("Another expiry run is in progress; exiting.\n");
    exit(0);
}
if (!isset($opts['quiet']) || $stats['orders_expired'] || $stats['reservations_released'] || $stats['errors'] || $stats['earnings_released']
    || $stats['collection_reminders'] || $stats['station_returns']) {
    printf(
        "[%s] expired orders: %d, reservations released: %d, errors: %d, guest carts purged: %d, seller earnings released: %d, collection reminders: %d, returned from stations: %d\n",
        date('Y-m-d H:i:s'), $stats['orders_expired'], $stats['reservations_released'], $stats['errors'], $stats['carts_purged'], $stats['earnings_released'],
        $stats['collection_reminders'], $stats['station_returns']
    );
}
exit($stats['errors'] > 0 ? 1 : 0);
