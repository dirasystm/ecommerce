<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\RateLimiter;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Manual M-Pesa payments (A4, C5). A submitted code is only a claim: it never confirms an order.
 * Staff verify it against the M-Pesa statement. Every decision is recorded once
 * (payment_verifications UNIQUE per submission) and audited.
 */
final class PaymentService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** Orders in these states accept an M-Pesa code from the customer. */
    public static function canSubmit(array $order): bool
    {
        return str_starts_with((string) $order['payment_method'], 'mpesa_')
            && in_array($order['status'], ['awaiting_payment', 'expired'], true)
            && !in_array($order['payment_status'], ['verified', 'awaiting_verification', 'refund_due', 'refunded'], true);
    }

    /**
     * Customer submits an M-Pesa transaction code. Marks the payment "awaiting verification" only.
     * @param array<string,mixed> $in transaction_code, payer_phone, amount
     * @return array<string,mixed> the submission row
     */
    public function submit(int $orderId, array $in, string $ip, ?int $actorUserId = null): array
    {
        Validator::validate($in, [
            'transaction_code' => 'required|mpesa_code',
            'payer_phone' => 'required|phone',
            'amount' => 'required|decimal',
        ], ['transaction_code' => 'M-Pesa transaction code', 'payer_phone' => 'Phone number used to pay', 'amount' => 'Amount paid']);
        if (!(new RateLimiter($this->db))->attempt('pay-submit|' . $orderId . '|' . $ip, 10, 3600)) {
            throw ValidationException::withMessage('Too many attempts. Please try again later or contact customer care.');
        }
        $code = strtoupper(trim((string) $in['transaction_code']));
        $amount = Money::normalize((string) $in['amount']);
        if (Money::cmp($amount, '0') <= 0) {
            throw new ValidationException(['amount' => 'Enter the amount you paid.']);
        }

        $result = $this->db->transaction(function () use ($orderId, $code, $amount, $in, $ip, $actorUserId) {
            $order = $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]) ?? throw ValidationException::withMessage('Order not found.');
            if (!self::canSubmit($order)) {
                throw ValidationException::withMessage('A payment code cannot be submitted for this order right now.');
            }
            $max = Settings::int('max_payment_submissions', 3);
            if ((int) $this->db->value('SELECT COUNT(*) FROM payment_submissions WHERE order_id = ?', [$orderId]) >= $max) {
                throw ValidationException::withMessage("This order has reached the limit of $max payment submissions. Please contact customer care.");
            }
            if ($this->db->value('SELECT 1 FROM payment_submissions WHERE transaction_code = ?', [$code])) {
                throw new ValidationException(['transaction_code' => 'This M-Pesa transaction code has already been submitted. Each payment can be used only once.']);
            }
            try {
                $id = $this->db->insert('payment_submissions', [
                    'order_id' => $orderId, 'method' => $order['payment_method'], 'transaction_code' => $code,
                    'payer_phone' => Validator::normalizePhone((string) $in['payer_phone']), 'amount_claimed' => $amount, 'ip_address' => $ip,
                ]);
            } catch (\PDOException $e) {
                if (Database::isDuplicateKey($e)) {     // concurrent submission of the same code
                    throw new ValidationException(['transaction_code' => 'This M-Pesa transaction code has already been submitted.']);
                }
                throw $e;
            }
            $this->db->exec("UPDATE orders SET payment_status = 'awaiting_verification' WHERE id = ?", [$orderId]);
            if ($order['status'] === 'awaiting_payment') {
                // C5: no reservation extension — the existing expiry still applies.
                (new OrderWorkflow($this->db))->transition($orderId, 'awaiting_verification', ['type' => 'customer', 'id' => $actorUserId], 'M-Pesa code submitted');
            } else {
                $this->db->insert('order_status_history', [
                    'order_id' => $orderId, 'from_status' => $order['status'], 'to_status' => $order['status'],
                    'actor_type' => 'customer', 'actor_user_id' => $actorUserId, 'note' => 'M-Pesa code submitted after the reservation expired',
                ]);
            }
            return (array) $this->db->one('SELECT * FROM payment_submissions WHERE id = ?', [$id]);
        });
        $this->notify($orderId, 'payment_submitted',
            'We have received your M-Pesa transaction code ' . $code . ' and are verifying it. This does not yet confirm your payment — we will let you know once it is verified.');
        return $result;
    }

    /**
     * Staff verify a submission against the M-Pesa statement.
     * Full payment on a fully reserved order → confirmed. Verified after expiry → requires resolution (C5).
     * @return array<string,mixed> the updated order
     */
    public function verify(int $submissionId, int $staffId, string $amountReceived, ?string $note): array
    {
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($amountReceived))) {
            throw new ValidationException(['amount_received' => 'Enter the amount shown on the M-Pesa statement.']);
        }
        $received = Money::normalize($amountReceived);
        $order = $this->db->transaction(function () use ($submissionId, $staffId, $received, $note) {
            $s = $this->lockPending($submissionId);
            $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$s['order_id']]);
            if (Money::cmp($received, (string) $order['grand_total']) < 0) {
                throw new ValidationException(['amount_received' => sprintf(
                    'Received %s is less than the order total %s. Reject the payment and record the amount received so a refund can be arranged.',
                    Money::format($received), Money::format((string) $order['grand_total'])
                )]);
            }
            $this->decide($s, 'verified', $staffId, $received, $note);
            $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$order['id']]);
            if (Money::cmp($received, (string) $order['grand_total']) > 0) {
                $this->db->insert('refunds', [
                    'order_id' => $order['id'], 'amount' => Money::sub($received, (string) $order['grand_total']),
                    'reason' => 'Overpayment on ' . $s['transaction_code'], 'created_by' => $staffId,
                ]);
            }
            $wf = new OrderWorkflow($this->db);
            $actor = ['type' => 'staff', 'id' => $staffId];
            $reservations = new ReservationService($this->db);
            if (in_array($order['status'], ['awaiting_payment', 'awaiting_verification'], true) && $reservations->fullyReserved((int) $order['id'])) {
                return $wf->transition((int) $order['id'], 'confirmed', $actor, 'Payment verified (' . $s['transaction_code'] . ')');
            }
            if ($order['status'] === 'expired') {
                // C5: payment arrived after the stock was released — never silently promise stock.
                return $wf->transition((int) $order['id'], 'requires_resolution', $actor, 'Payment verified after reservation expired', [
                    'resolution_reason' => 'payment_after_expiry',
                    'resolution_details' => 'Payment ' . $s['transaction_code'] . ' of ' . Money::format($received) . ' verified after the reservation expired.',
                ]);
            }
            if ($order['status'] === 'cancelled') {
                $this->db->exec("UPDATE orders SET payment_status = 'refund_due' WHERE id = ?", [$order['id']]);
                $this->db->insert('refunds', ['order_id' => $order['id'], 'amount' => $received, 'reason' => 'Payment received for a cancelled order', 'created_by' => $staffId]);
                return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
            }
            throw ValidationException::withMessage('This order is not in a state where a payment can be applied.');
        });
        OrderNotifier::statusChanged($order);
        if ($order['status'] !== 'confirmed') {
            $this->notify((int) $order['id'], 'payment_verified', 'Your M-Pesa payment for order ' . $order['order_number'] . ' has been verified.');
        }
        return $order;
    }

    /**
     * Staff reject a submission. If money WAS received (e.g. underpaid), it is recorded as a refund due.
     * @return array<string,mixed> the updated order
     */
    public function reject(int $submissionId, int $staffId, string $reason, ?string $note, string $moneyReceived = ''): array
    {
        $reasons = self::REJECT_REASONS;
        if (!isset($reasons[$reason])) {
            throw new ValidationException(['reason' => 'Choose a reason.']);
        }
        $received = trim($moneyReceived) === '' ? null : (preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($moneyReceived))
            ? Money::normalize($moneyReceived) : throw new ValidationException(['money_received' => 'Enter a valid amount or leave blank.']));
        $order = $this->db->transaction(function () use ($submissionId, $staffId, $reason, $reasons, $note, $received) {
            $s = $this->lockPending($submissionId);
            $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$s['order_id']]);
            $this->decide($s, 'rejected', $staffId, $received, trim($reasons[$reason] . ($note ? ': ' . $note : '')));
            $paymentStatus = 'rejected';
            if ($received !== null && Money::cmp($received, '0') > 0) {
                $this->db->insert('refunds', [
                    'order_id' => $order['id'], 'amount' => $received,
                    'reason' => 'Rejected payment ' . $s['transaction_code'] . ' (' . $reasons[$reason] . ')', 'created_by' => $staffId,
                ]);
                $paymentStatus = 'refund_due';
            }
            $this->db->exec('UPDATE orders SET payment_status = ? WHERE id = ?', [$paymentStatus, $order['id']]);
            if ($order['status'] === 'awaiting_verification') {
                return (new OrderWorkflow($this->db))->transition((int) $order['id'], 'awaiting_payment', ['type' => 'staff', 'id' => $staffId], 'Payment rejected: ' . $reasons[$reason]);
            }
            return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
        });
        $msg = 'We could not verify your M-Pesa payment ' . ($this->db->value('SELECT transaction_code FROM payment_submissions WHERE id = ?', [$submissionId]))
            . ' for order ' . $order['order_number'] . ' (' . $reasons[$reason] . ').';
        $msg .= $order['payment_status'] === 'refund_due'
            ? ' Our team will contact you to arrange a refund of the amount received.'
            : ($order['status'] === 'awaiting_payment' ? ' Please check the details and submit the correct code from your order page.' : ' Please contact customer care.');
        $this->notify((int) $order['id'], 'payment_rejected', $msg);
        return $order;
    }

    public const REJECT_REASONS = [
        'not_found' => 'Transaction not found on our M-Pesa statement',
        'wrong_amount' => 'Amount paid does not match the order total',
        'wrong_account' => 'Paid to the wrong account / reference',
        'duplicate' => 'Transaction already used for another payment',
        'other' => 'Other',
    ];

    /** @return array<string,mixed> */
    private function lockPending(int $submissionId): array
    {
        $s = $this->db->one('SELECT * FROM payment_submissions WHERE id = ? FOR UPDATE', [$submissionId]) ?? throw ValidationException::withMessage('Payment not found.');
        if ($s['status'] !== 'pending') {
            throw ValidationException::withMessage('This payment has already been ' . $s['status'] . '.');
        }
        return $s;
    }

    private function decide(array $s, string $decision, int $staffId, ?string $amount, ?string $note): void
    {
        $this->db->exec("UPDATE payment_submissions SET status = ? WHERE id = ? AND status = 'pending'", [$decision, $s['id']]);
        // UNIQUE(submission_id): a second decision on the same submission is impossible.
        $this->db->insert('payment_verifications', [
            'submission_id' => $s['id'], 'order_id' => $s['order_id'], 'decision' => $decision, 'amount_confirmed' => $amount,
            'actor_user_id' => $staffId, 'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
        ]);
        AuditLogger::log('payment.' . $decision, 'payment_submission', (int) $s['id'],
            ['status' => 'pending'], ['status' => $decision, 'order_id' => $s['order_id'], 'code' => $s['transaction_code'], 'amount' => $amount, 'note' => $note], $staffId);
    }

    private function notify(int $orderId, string $template, string $text): void
    {
        try {
            $o = $this->db->one('SELECT o.*, u.notify_email, u.notify_sms FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?', [$orderId]);
            $n = new NotificationService();
            $ctx = ['order_id' => $orderId, 'user_id' => $o['user_id'] ? (int) $o['user_id'] : null];
            if ($o['customer_email'] && ($o['user_id'] === null || (int) $o['notify_email'] === 1)) {
                $n->email((string) $o['customer_email'], "Order {$o['order_number']}: payment update", "Hello {$o['customer_name']},\n\n$text\n\n" . brand_name(), $template, $ctx);
            }
            if ($o['user_id'] === null || (int) $o['notify_sms'] === 1) {
                $n->sms((string) $o['customer_phone'], brand_name() . ": $text", $template, $ctx);
            }
        } catch (\Throwable $e) {
            \Waimart\Core\Logger::error('Payment notification failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }
}
