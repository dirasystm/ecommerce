<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Returns (C7 "do not restore stock automatically", C9 #9).
 *   requested → approved → received → (inspect each item) → completed
 *   requested → rejected
 * Stock goes back ONLY through inspection: "restock" adds the units to on-hand; "write off"
 * records the units as unsellable. Both are ledger movements recorded exactly once per item.
 * The refund is a separate staff decision at completion.
 */
final class ReturnService
{
    public const REASONS = [
        'damaged' => 'Item arrived damaged',
        'defective' => 'Item is faulty / does not work',
        'wrong_item' => 'Wrong item delivered',
        'not_as_described' => 'Not as described',
        'changed_mind' => 'Changed my mind',
        'other' => 'Other',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /**
     * Returnable items with the quantity still returnable. Eligibility is per seller part: the part has been
     * delivered or collected, it is within the return window counted from then, and it has no open return.
     * (Waimart delivers every part, so returns also come back through Waimart, one return per part.)
     * @return list<array<string,mixed>>
     */
    public function returnableItems(array $order): array
    {
        if ($this->db->value("SELECT 1 FROM return_requests WHERE order_id = ? AND seller_order_id IS NULL AND status IN ('requested','approved','received')", [$order['id']])) {
            return [];   // an open return recorded before the marketplace upgrade covers the whole order
        }
        $window = Settings::int('return_window_days', 7) * 86400;
        $rows = $this->db->all(
            "SELECT oi.*, so.sub_number, s.name AS seller_name, s.is_platform,
                    COALESCE(so.delivered_at, so.collected_at, o.completed_at) AS handed_at
               FROM order_items oi JOIN seller_orders so ON so.id = oi.seller_order_id JOIN sellers s ON s.id = so.seller_id
               JOIN orders o ON o.id = oi.order_id
              WHERE oi.order_id = ? AND so.status IN ('delivered','collected')
                AND NOT EXISTS (SELECT 1 FROM return_requests r WHERE r.seller_order_id = so.id AND r.status IN ('requested','approved','received'))
              ORDER BY so.id, oi.id",
            [$order['id']]
        );
        $out = [];
        foreach ($rows as $r) {
            $left = (int) $r['fulfilled_qty'] - (int) $r['returned_qty'];
            if ($left > 0 && $r['handed_at'] !== null && time() <= strtotime((string) $r['handed_at']) + $window) {
                $out[] = $r + ['returnable' => $left];
            }
        }
        return $out;
    }

    /** Something on the order can still be returned online. */
    public function eligible(array $order): bool
    {
        return $this->returnableItems($order) !== [];
    }

    /**
     * Items from several sellers become one return per seller part, so each is handled (and refunded, and charged
     * to its seller) on its own.
     * @param array<int|string,mixed> $quantities order_item_id => qty
     * @return list<array<string,mixed>> the return requests created
     */
    public function request(int $orderId, array $quantities, string $reason, ?string $details, ?int $userId): array
    {
        if (!isset(self::REASONS[$reason])) {
            throw new ValidationException(['reason' => 'Choose a reason for the return.']);
        }
        if ($reason === 'other' && trim((string) $details) === '') {
            throw new ValidationException(['details' => 'Please tell us more about the problem.']);
        }
        $rets = $this->db->transaction(function () use ($orderId, $quantities, $reason, $details, $userId) {
            $order = $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]) ?? throw ValidationException::withMessage('Order not found.');
            $returnable = array_column($this->returnableItems($order), null, 'id');
            if (!$returnable) {
                throw ValidationException::withMessage('This order can no longer be returned online (the return window may have passed). Please contact customer care.');
            }
            $byPart = [];
            foreach ($quantities as $itemId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                if (!isset($returnable[(int) $itemId]) || $qty > (int) $returnable[(int) $itemId]['returnable']) {
                    throw new ValidationException(['items' => 'Choose quantities up to what you received.']);
                }
                $byPart[(int) $returnable[(int) $itemId]['seller_order_id']][(int) $itemId] = $qty;
            }
            if (!$byPart) {
                throw new ValidationException(['items' => 'Choose at least one item to return.']);
            }
            $out = [];
            foreach ($byPart as $soId => $lines) {
                $returnId = $this->db->insert('return_requests', [
                    'return_number' => $this->newNumber(), 'order_id' => $orderId, 'seller_order_id' => $soId, 'user_id' => $userId,
                    'reason' => $reason, 'details' => trim((string) $details) !== '' ? mb_substr(trim((string) $details), 0, 1000) : null,
                ]);
                foreach ($lines as $itemId => $qty) {
                    $this->db->insert('return_items', ['return_id' => $returnId, 'order_item_id' => $itemId, 'quantity' => $qty, 'note' => null]);
                }
                $out[] = (array) $this->db->one('SELECT * FROM return_requests WHERE id = ?', [$returnId]);
            }
            // The order shows "return requested" only once everything was handed over (otherwise its parts drive it).
            if (in_array($order['status'], ['delivered', 'collected'], true)) {
                (new OrderWorkflow($this->db))->transition($orderId, 'return_requested', ['type' => 'customer', 'id' => $userId], 'Return requested: ' . self::REASONS[$reason]);
            }
            return $out;
        });
        $numbers = implode(', ', array_column($rets, 'return_number'));
        $this->notify($orderId, 'return_requested', ['return_numbers' => $numbers]);
        foreach ($rets as $r) {
            $this->notifySeller($r, 'seller_return_requested', "Return {$r['return_number']} requested",
                'A customer has asked to return items from order ' . $this->subNumber($r) . ' (reason: ' . self::REASONS[$reason] . ").\n"
                . brand_name() . ' will collect and inspect the items. Earnings for this order are held until the return is closed.');
        }
        return $rets;
    }

    public function approve(int $returnId, int $staffId, ?string $note): void
    {
        $r = $this->move($returnId, 'requested', 'approved', $staffId, $note, ['decided_by' => $staffId, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => $note]);
        $this->notify((int) $r['order_id'], 'return_approved', ['return_number' => (string) $r['return_number'], 'staff_note' => $note ? " Note: $note" : '']);
    }

    public function reject(int $returnId, int $staffId, string $note): void
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new ValidationException(['decision_note' => 'Explain why the return is rejected (the customer will see this).']);
        }
        $r = $this->db->transaction(function () use ($returnId, $staffId, $note) {
            $r = $this->move($returnId, 'requested', 'rejected', $staffId, $note, ['decided_by' => $staffId, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => $note]);
            $this->restoreOrderStatus((int) $r['order_id'], $staffId, 'Return rejected');
            return $r;
        });
        $this->notify((int) $r['order_id'], 'return_rejected', ['return_number' => (string) $r['return_number'], 'reason' => $note]);
        $this->notifySeller($r, 'seller_return_rejected', "Return {$r['return_number']} not accepted",
            brand_name() . ' did not accept the return on order ' . $this->subNumber($r) . '. Your earnings for it are no longer held by this return.');
    }

    public function receive(int $returnId, int $staffId): void
    {
        $this->move($returnId, 'approved', 'received', $staffId, null, ['received_by' => $staffId, 'received_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Inspects one returned line exactly once. restock → on-hand += qty; write_off → recorded, no stock.
     * @param 'restock'|'write_off' $outcome
     */
    public function inspect(int $returnId, int $returnItemId, string $outcome, ?string $note, int $staffId): void
    {
        if (!in_array($outcome, ['restock', 'write_off'], true)) {
            throw new ValidationException(['outcome' => 'Choose restock or write off.']);
        }
        $this->db->transaction(function () use ($returnId, $returnItemId, $outcome, $note, $staffId) {
            $r = $this->db->one('SELECT * FROM return_requests WHERE id = ? FOR UPDATE', [$returnId]) ?? throw ValidationException::withMessage('Return not found.');
            if ($r['status'] !== 'received') {
                throw ValidationException::withMessage('Items can be inspected only after the return has been received.');
            }
            $ri = $this->db->one(
                'SELECT ri.*, oi.variant_id, oi.order_id FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id WHERE ri.id = ? AND ri.return_id = ? FOR UPDATE',
                [$returnItemId, $returnId]
            ) ?? throw ValidationException::withMessage('Item not found.');
            // Exactly once: guarded update (plus UNIQUE(return_item_id, movement_type) on the ledger).
            if ($this->db->exec("UPDATE return_items SET inspection = ?, inspected_by = ?, inspected_at = NOW(), note = ? WHERE id = ? AND inspection = 'pending'",
                [$outcome, $staffId, $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null, $returnItemId]) !== 1) {
                throw ValidationException::withMessage('This item has already been inspected.');
            }
            $inv = new InventoryService($this->db);
            $inv->lock((int) $ri['variant_id']);
            $qty = (int) $ri['quantity'];
            $inv->applyChange((int) $ri['variant_id'], $outcome === 'restock' ? $qty : 0, 0, $outcome === 'restock' ? 'return_restock' : 'return_writeoff', [
                'actor_type' => 'staff', 'actor_user_id' => $staffId, 'return_item_id' => $returnItemId, 'order_id' => (int) $ri['order_id'],
                'reason' => $outcome === 'restock' ? 'return_passed' : 'return_failed', 'note' => $note,
            ]);
            $this->db->exec('UPDATE order_items SET returned_qty = returned_qty + ? WHERE id = ?', [$qty, $ri['order_item_id']]);
            AuditLogger::log('return.item_inspected', 'return', $returnId, null, ['return_item_id' => $returnItemId, 'outcome' => $outcome, 'qty' => $qty, 'note' => $note], $staffId);
        });
    }

    /** Closes a fully inspected return and records the refund decision. */
    public function complete(int $returnId, int $staffId, string $refundAmount, ?string $note): void
    {
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($refundAmount))) {
            throw new ValidationException(['refund_amount' => 'Enter the refund amount (0 for no refund).']);
        }
        $amount = Money::normalize($refundAmount);
        $r = $this->db->transaction(function () use ($returnId, $staffId, $amount, $note) {
            $r = $this->db->one('SELECT * FROM return_requests WHERE id = ? FOR UPDATE', [$returnId]) ?? throw ValidationException::withMessage('Return not found.');
            if ($r['status'] !== 'received') {
                throw ValidationException::withMessage('Only a received return can be completed.');
            }
            if ($this->db->value("SELECT 1 FROM return_items WHERE return_id = ? AND inspection = 'pending'", [$returnId])) {
                throw ValidationException::withMessage('Inspect every item before completing the return.');
            }
            $max = $this->maxRefund($returnId, true);   // holds the seller part until commit (no concurrent over-refund)
            if (Money::cmp($amount, $max) > 0) {
                throw new ValidationException(['refund_amount' => 'The refund cannot exceed ' . Money::format($max) . ' (the value of the returned items).']);
            }
            if (Money::isZero($amount) && trim((string) $note) === '') {
                throw new ValidationException(['completion_note' => 'Explain why no refund is given (the customer will see this).']);
            }
            $this->db->exec("UPDATE return_requests SET status = 'completed', completed_at = NOW(), refund_amount = ?, completion_note = ? WHERE id = ?",
                [$amount, $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null, $returnId]);
            if (!Money::isZero($amount)) {
                $refundId = $this->db->insert('refunds', ['order_id' => $r['order_id'], 'seller_order_id' => $r['seller_order_id'], 'return_id' => $returnId,
                    'amount' => $amount, 'reason' => 'Return ' . $r['return_number'], 'created_by' => $staffId]);
                $this->db->exec("UPDATE orders SET payment_status = 'refund_due' WHERE id = ?", [$r['order_id']]);
                if ($r['seller_order_id'] !== null) {
                    (new SellerLedgerService($this->db))->refundPart((int) $r['seller_order_id'], $refundId, $amount, $staffId);
                }
            }
            // Everything that was supplied (declined/cancelled parts never were) has come back, and no other return is open.
            $allBack = !(bool) $this->db->value(
                "SELECT 1 FROM order_items oi JOIN seller_orders so ON so.id = oi.seller_order_id
                  WHERE oi.order_id = ? AND so.status NOT IN ('cancelled','rejected') AND oi.returned_qty < oi.quantity",
                [$r['order_id']]
            ) && !$this->db->value("SELECT 1 FROM return_requests WHERE order_id = ? AND status IN ('requested','approved','received')", [$r['order_id']])
              && $this->db->value('SELECT status FROM orders WHERE id = ?', [$r['order_id']]) === 'return_requested';
            if ($allBack) {
                (new OrderWorkflow($this->db))->transition((int) $r['order_id'], 'returned', ['type' => 'staff', 'id' => $staffId], 'Return ' . $r['return_number'] . ' completed');
            } else {
                $this->restoreOrderStatus((int) $r['order_id'], $staffId, 'Partial return ' . $r['return_number'] . ' completed');
            }
            AuditLogger::log('return.completed', 'return', $returnId, ['status' => 'received'], ['status' => 'completed', 'refund' => $amount, 'note' => $note], $staffId);
            return $r;
        });
        $this->notify((int) $r['order_id'], 'return_completed', ['return_number' => (string) $r['return_number'],
            'refund_outcome' => Money::isZero($amount) ? 'No refund is due' . ($note ? ": $note" : '.') : 'A refund of ' . Money::format($amount) . ' has been approved and our team will send it to you.']);
        $this->notifySeller($r, 'seller_return_completed', "Return {$r['return_number']} closed",
            'The return on order ' . $this->subNumber($r) . ' has been closed. '
            . (Money::isZero($amount) ? 'No refund was given, so your earnings are unchanged.' : 'The customer is refunded ' . Money::format($amount) . '; the matching share of your sale (less ' . brand_name() . '\'s commission on it) is deducted in your ledger.'));
    }

    /**
     * The most that may be refunded for a return: the value of its units at the price paid, and never more than
     * the customer actually paid for that seller part (after any discount) less what is already refunded on it.
     */
    public function maxRefund(int $returnId, bool $lock = false): string
    {
        $value = Money::normalize((string) $this->db->value(
            'SELECT COALESCE(SUM(ri.quantity * oi.unit_price), 0) FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id WHERE ri.return_id = ?',
            [$returnId]
        ));
        $soId = $this->db->value('SELECT seller_order_id FROM return_requests WHERE id = ?', [$returnId]);
        if (!$soId) {
            return $value;   // returns recorded before the marketplace upgrade
        }
        return Money::min($value, (new RefundService($this->db))->refundableForPart((int) $soId, $lock));
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function move(int $returnId, string $from, string $to, int $staffId, ?string $note, array $extra): array
    {
        return $this->db->transaction(function () use ($returnId, $from, $to, $staffId, $note, $extra) {
            $r = $this->db->one('SELECT * FROM return_requests WHERE id = ? FOR UPDATE', [$returnId]) ?? throw ValidationException::withMessage('Return not found.');
            if ($r['status'] !== $from) {
                throw ValidationException::withMessage("This return is {$r['status']}, so it cannot be marked $to.");
            }
            $sets = ['status = ?'];
            $params = [$to];
            foreach ($extra as $k => $v) {
                $sets[] = "`$k` = ?";
                $params[] = $v;
            }
            $params[] = $returnId;
            $this->db->exec('UPDATE return_requests SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            AuditLogger::log("return.$to", 'return', $returnId, ['status' => $from], ['status' => $to, 'note' => $note], $staffId);
            return $r;
        });
    }

    /** Back to delivered/collected once no return on the order is still open (another part may still have one). */
    private function restoreOrderStatus(int $orderId, int $staffId, string $note): void
    {
        $o = $this->db->one('SELECT status, fulfilment_method FROM orders WHERE id = ?', [$orderId]);
        if (($o['status'] ?? '') !== 'return_requested'
            || $this->db->value("SELECT 1 FROM return_requests WHERE order_id = ? AND status IN ('requested','approved','received')", [$orderId])) {
            return;
        }
        (new OrderWorkflow($this->db))->transition($orderId, $o['fulfilment_method'] === 'pickup' ? 'collected' : 'delivered', ['type' => 'staff', 'id' => $staffId], $note);
    }

    /** @param array<string,mixed> $r a return request */
    private function subNumber(array $r): string
    {
        return (string) ($this->db->value('SELECT sub_number FROM seller_orders WHERE id = ?', [$r['seller_order_id']]) ?: '');
    }

    /** Tells a marketplace seller about a return on their part (the Official Store is Waimart itself). */
    private function notifySeller(array $r, string $template, string $subject, string $body): void
    {
        if ($r['seller_order_id'] === null) {
            return;
        }
        $sellerId = (int) $this->db->value('SELECT seller_id FROM seller_orders WHERE id = ?', [$r['seller_order_id']]);
        (new SellerNotifier($this->db))->send($sellerId, $template, $subject, $body, false, ['owner', 'manager'], (int) $r['order_id']);
    }

    private function newNumber(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $n = 'RT-' . Crypto::code(8);
            if (!$this->db->value('SELECT 1 FROM return_requests WHERE return_number = ?', [$n])) {
                return $n;
            }
        }
        throw new \RuntimeException('Could not allocate a return number.');
    }

    /** @param array<string,string> $vars the template's own placeholders */
    private function notify(int $orderId, string $template, array $vars): void
    {
        try {
            $o = $this->db->one('SELECT o.*, u.notify_email, u.notify_sms FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?', [$orderId]);
            (new NotificationService())->sendTemplate($template, $vars + MessageTemplates::orderVars($o),
                ['email' => $o['customer_email'], 'phone' => $o['customer_phone'], 'prefs' => $o['user_id'] === null ? null : $o],
                ['order_id' => $orderId, 'user_id' => $o['user_id'] ? (int) $o['user_id'] : null]);
        } catch (\Throwable $e) {
            \Waimart\Core\Logger::error('Return notification failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }
}
