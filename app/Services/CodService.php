<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;

/**
 * Cash-on-delivery collection, recorded separately from delivery/collection status (H):
 * "delivered" says the goods arrived; this records who collected how much cash, and when.
 */
final class CodService
{
    /** Cash is collected when goods are handed over. */
    public const COLLECTABLE = ['dispatched', 'out_for_delivery', 'delivered', 'collected'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    public function record(int $orderId, string $amount, int $staffId, ?string $note): array
    {
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($amount))) {
            throw new ValidationException(['amount_collected' => 'Enter the cash amount collected.']);
        }
        $collected = Money::normalize($amount);
        return $this->db->transaction(function () use ($orderId, $collected, $staffId, $note) {
            $o = $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]) ?? throw ValidationException::withMessage('Order not found.');
            if ($o['payment_method'] !== 'cod') {
                throw ValidationException::withMessage('This is not a cash-on-delivery order.');
            }
            if (!in_array($o['status'], self::COLLECTABLE, true)) {
                throw ValidationException::withMessage('Cash can be recorded only once the order has been handed over.');
            }
            if ($this->db->value('SELECT 1 FROM cod_collections WHERE order_id = ?', [$orderId])) {
                throw ValidationException::withMessage('Cash for this order has already been recorded.');
            }
            $mismatch = Money::cmp($collected, (string) $o['grand_total']) !== 0;
            if ($mismatch && trim((string) $note) === '') {
                throw new ValidationException(['note' => sprintf(
                    'Collected %s differs from the expected %s. Explain the difference.', Money::format($collected), Money::format((string) $o['grand_total'])
                )]);
            }
            $this->db->insert('cod_collections', [
                'order_id' => $orderId, 'amount_expected' => $o['grand_total'], 'amount_collected' => $collected,
                'collected_by' => $staffId, 'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            ]);
            $this->db->exec("UPDATE orders SET payment_status = 'cod_collected' WHERE id = ?", [$orderId]);
            AuditLogger::log('cod.collected', 'order', $orderId, ['payment_status' => $o['payment_status']],
                ['payment_status' => 'cod_collected', 'expected' => $o['grand_total'], 'collected' => $collected, 'note' => $note], $staffId);
            return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        });
    }
}
