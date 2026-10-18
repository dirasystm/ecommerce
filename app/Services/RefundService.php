<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;

/** Refund obligations (refunds.manage): mark paid with method + reference, or cancel with a reason. Audited. */
final class RefundService
{
    public const METHODS = ['mpesa' => 'M-Pesa (B2C / Send Money)', 'cash' => 'Cash', 'bank' => 'Bank transfer', 'other' => 'Other'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    public function markPaid(int $refundId, int $staffId, string $method, string $reference, ?string $note): void
    {
        if (!isset(self::METHODS[$method])) {
            throw new ValidationException(['method' => 'Choose how the refund was paid.']);
        }
        $reference = strtoupper(trim($reference));
        if (!preg_match('/^[A-Z0-9 \-\/]{3,60}$/', $reference)) {
            throw new ValidationException(['reference' => 'Enter the payment reference (e.g. the M-Pesa transaction code).']);
        }
        $r = $this->db->transaction(function () use ($refundId, $staffId, $method, $reference, $note) {
            $r = $this->lockDue($refundId);
            $this->db->exec("UPDATE refunds SET status = 'paid', method = ?, reference = ?, paid_by = ?, paid_at = NOW(), note = ? WHERE id = ? AND status = 'due'",
                [$method, $reference, $staffId, $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null, $refundId]);
            $this->settleOrder((int) $r['order_id']);
            AuditLogger::log('refund.paid', 'refund', $refundId, ['status' => 'due'], ['status' => 'paid', 'amount' => $r['amount'], 'method' => $method, 'reference' => $reference], $staffId);
            return $r;
        });
        $this->notify((int) $r['order_id'], 'Your refund of ' . Money::format((string) $r['amount']) . ' has been sent (' . self::METHODS[$method] . ", reference $reference).");
    }

    public function cancel(int $refundId, int $staffId, string $note): void
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new ValidationException(['note' => 'Explain why this refund is cancelled.']);
        }
        $this->db->transaction(function () use ($refundId, $staffId, $note) {
            $r = $this->lockDue($refundId);
            $this->db->exec("UPDATE refunds SET status = 'cancelled', note = ? WHERE id = ? AND status = 'due'", [mb_substr($note, 0, 500), $refundId]);
            $this->settleOrder((int) $r['order_id']);
            AuditLogger::log('refund.cancelled', 'refund', $refundId, ['status' => 'due'], ['status' => 'cancelled', 'note' => $note], $staffId);
        });
    }

    /** @return array<string,mixed> */
    private function lockDue(int $refundId): array
    {
        $r = $this->db->one('SELECT * FROM refunds WHERE id = ? FOR UPDATE', [$refundId]) ?? throw ValidationException::withMessage('Refund not found.');
        if ($r['status'] !== 'due') {
            throw ValidationException::withMessage('This refund is already ' . $r['status'] . '.');
        }
        return $r;
    }

    /** When nothing is still due, an order in "refund due" becomes "refunded" (if anything was paid). */
    private function settleOrder(int $orderId): void
    {
        if ($this->db->value("SELECT 1 FROM refunds WHERE order_id = ? AND status = 'due'", [$orderId])) {
            return;
        }
        $paid = (bool) $this->db->value("SELECT 1 FROM refunds WHERE order_id = ? AND status = 'paid'", [$orderId]);
        // Nothing paid out: restore the status the money actually has (COD cash vs verified M-Pesa).
        $restore = $paid ? 'refunded'
            : ((string) $this->db->value('SELECT payment_method FROM orders WHERE id = ?', [$orderId]) === 'cod' ? 'cod_collected' : 'verified');
        $this->db->exec("UPDATE orders SET payment_status = ? WHERE id = ? AND payment_status = 'refund_due'", [$restore, $orderId]);
    }

    private function notify(int $orderId, string $text): void
    {
        try {
            $o = $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            $n = new NotificationService();
            $ctx = ['order_id' => $orderId, 'user_id' => $o['user_id'] ? (int) $o['user_id'] : null];
            if ($o['customer_email']) {
                $n->email((string) $o['customer_email'], "Order {$o['order_number']}: refund sent", "Hello {$o['customer_name']},\n\n$text\n\nWaimart", 'refund_paid', $ctx);
            }
            $n->sms((string) $o['customer_phone'], "Waimart: $text", 'refund_paid', $ctx);
        } catch (\Throwable $e) {
            \Waimart\Core\Logger::error('Refund notification failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }
}
