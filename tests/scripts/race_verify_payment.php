<?php

declare(strict_types=1);

/**
 * Worker for HeavyConcurrencyTest: waits until a shared start time, then staff verify one M-Pesa submission.
 * Usage: php race_verify_payment.php <submissionId> <staffId> <amount> <startAtMicrotime>
 * Prints one JSON line: {"ok":bool,"status":string|null,"error":string|null}
 */

putenv('WAIMART_ENV=testing');
require dirname(__DIR__, 2) . '/bootstrap.php';

[, $submissionId, $staffId, $amount, $startAt] = $argv;
$_SESSION = [];

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $o = (new Waimart\Services\PaymentService())->verify((int) $submissionId, (int) $staffId, $amount, 'race');
    echo json_encode(['ok' => true, 'status' => $o['status'], 'error' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'status' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
