<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;

/**
 * Staff resolution of orders flagged "requires resolution" (C5), typically a payment verified
 * after the stock reservation expired. Options:
 *   - re-reserve: if stock is available now, reserve it (held until fulfilment) and confirm;
 *   - cancel with refund: cancel and record a refund obligation for the money received.
 */
final class ResolutionService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,mixed> the confirmed order */
    public function reReserve(int $orderId, int $staffId, ?string $note): array
    {
        $order = $this->db->transaction(function () use ($orderId, $staffId, $note) {
            $o = $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]) ?? throw ValidationException::withMessage('Order not found.');
            if ($o['status'] !== 'requires_resolution') {
                throw ValidationException::withMessage('This order is not awaiting resolution.');
            }
            if ($o['payment_status'] !== 'verified') {
                throw ValidationException::withMessage('Only orders with a verified payment can be re-reserved.');
            }
            $items = $this->db->all('SELECT id, variant_id, quantity, product_name FROM order_items WHERE order_id = ? ORDER BY variant_id', [$orderId]);
            $reservations = new ReservationService($this->db);
            try {
                $reservations->lockAndCheck(array_map(fn ($i) => ['variant_id' => (int) $i['variant_id'], 'quantity' => (int) $i['quantity'], 'name' => $i['product_name']], $items));
            } catch (InsufficientStockException $e) {
                $short = implode(', ', array_map(fn ($s) => sprintf('%s (need %d, %d available)', $s['name'], $s['requested'], $s['available']), $e->shortages));
                throw ValidationException::withMessage("Cannot re-reserve — not enough stock for: $short. Cancel the order with a refund instead, or wait for new stock.");
            }
            // Paid order: hold until fulfilment (no expiry).
            $reservations->reserve($orderId, array_map(fn ($i) => ['order_item_id' => (int) $i['id'], 'variant_id' => (int) $i['variant_id'], 'quantity' => (int) $i['quantity']], $items), null);
            return (new OrderWorkflow($this->db))->transition($orderId, 'confirmed', ['type' => 'staff', 'id' => $staffId],
                'Resolved: stock re-reserved', ['resolution_outcome' => 're_reserved', 'resolution_note' => $note]);
        });
        OrderNotifier::statusChanged($order);
        return $order;
    }

    /** @return array<string,mixed> the cancelled order (refund recorded by the workflow) */
    public function cancelWithRefund(int $orderId, int $staffId, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'Give a reason (it is shown to the customer and audited).']);
        }
        $o = $this->db->one('SELECT status, payment_status FROM orders WHERE id = ?', [$orderId]) ?? throw ValidationException::withMessage('Order not found.');
        if ($o['status'] !== 'requires_resolution') {
            throw ValidationException::withMessage('This order is not awaiting resolution.');
        }
        $order = (new OrderWorkflow($this->db))->transition($orderId, 'cancelled', ['type' => 'staff', 'id' => $staffId], 'Resolved: cancelled', [
            'reason' => trim($reason),
            'resolution_outcome' => $o['payment_status'] === 'verified' ? 'cancelled_refund_due' : 'cancelled',
            'resolution_note' => trim($reason),
        ]);
        OrderNotifier::statusChanged($order);
        return $order;
    }
}
