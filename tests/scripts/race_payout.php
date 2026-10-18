<?php

declare(strict_types=1);

/**
 * Worker for PayoutConcurrencyTest: waits until a shared start time, then performs one payout action.
 * Usage:
 *   php race_payout.php request <sellerId> <userId> <amount> <startAt>
 *   php race_payout.php paid <payoutId> <staffId> <reference> <amount> <startAt>
 *   php race_payout.php release <startAt>
 * Prints one JSON line: {"ok":bool,"result":mixed,"error":string|null}
 */

putenv('WAIMART_ENV=testing');
require dirname(__DIR__, 2) . '/bootstrap.php';

$args = array_slice($argv, 1);
$mode = array_shift($args);
$startAt = (float) array_pop($args);
$_SESSION = [];

while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    $result = match ($mode) {
        'request' => (new Waimart\Services\PayoutService())->request((int) $args[0], (int) $args[1], $args[2])['payout_number'],
        'paid' => (new Waimart\Services\PayoutService())->markPaid((int) $args[0], (int) $args[1], 'mpesa', $args[2], $args[3]),
        'release' => (new Waimart\Services\SellerLedgerService())->releaseEligible(),
        default => throw new InvalidArgumentException("unknown mode $mode"),
    };
    echo json_encode(['ok' => true, 'result' => $result, 'error' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'result' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
