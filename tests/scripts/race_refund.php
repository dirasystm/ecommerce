<?php

declare(strict_types=1);

/**
 * Worker for RefundConcurrencyTest: waits until a shared start time, then records one refund decision.
 * Usage:
 *   php race_refund.php return <returnId> <staffId> <amount> <startAt>
 *   php race_refund.php dispute <disputeId> <staffId> <amount> <startAt>
 * Prints one JSON line: {"ok":bool,"error":string|null}
 */

putenv('WAIMART_ENV=testing');
require dirname(__DIR__, 2) . '/bootstrap.php';

[, $mode, $id, $staff, $amount, $startAt] = $argv;
$_SESSION = [];

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    match ($mode) {
        'return' => (new Waimart\Services\ReturnService())->complete((int) $id, (int) $staff, $amount, null),
        'dispute' => (new Waimart\Services\DisputeService())->resolve((int) $id, (int) $staff, 'refund_partial', 'Race test refund', $amount),
        default => throw new InvalidArgumentException("unknown mode $mode"),
    };
    echo json_encode(['ok' => true, 'error' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
