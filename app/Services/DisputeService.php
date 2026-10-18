<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\HttpException;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Disputes about one marketplace seller's part of an order, handled by Waimart (disputes.manage).
 *   open ⇄ awaiting_seller / awaiting_customer → resolved (with a resolution)   ·   customer may withdraw → closed
 * An open dispute holds the seller's earnings for that part (SellerLedgerService::releaseEligible). A refund
 * resolution creates a refund obligation for the part and the matching seller ledger deduction.
 *
 * Privacy: the seller sees the customer's first name only; customer and seller see staff as "Waimart". Internal
 * staff notes are never shown outside the admin.
 */
final class DisputeService
{
    public const REASONS = [
        'not_received' => 'Item not received',
        'damaged' => 'Item arrived damaged',
        'wrong_item' => 'Wrong item',
        'not_as_described' => 'Not as described',
        'missing_parts' => 'Parts or accessories missing',
        'other' => 'Other',
    ];
    public const RESOLUTIONS = [
        'refund_full' => 'Full refund to the customer',
        'refund_partial' => 'Partial refund to the customer',
        'return_required' => 'Customer to return the item first',
        'seller_favoured' => 'Decided in the seller\'s favour',
        'no_action' => 'No action needed',
        'other' => 'Other',
    ];
    public const STATUS_LABELS = [
        'open' => 'Open — with {store}', 'awaiting_seller' => 'Waiting for the seller', 'awaiting_customer' => 'Waiting for the customer',
        'resolved' => 'Resolved', 'closed' => 'Withdrawn',
    ];
    public const OPEN = ['open', 'awaiting_seller', 'awaiting_customer'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    // ------------------------------------------------------------------ eligibility

    /** Marketplace parts of this order a customer can raise a dispute about now. @return list<array<string,mixed>> */
    public function disputableParts(array $order): array
    {
        $window = Settings::int('return_window_days', 7) * 86400;
        $rows = $this->db->all(
            "SELECT so.id, so.sub_number, so.status, s.name AS seller_name, COALESCE(so.delivered_at, so.collected_at) AS handed_at
               FROM seller_orders so JOIN sellers s ON s.id = so.seller_id
              WHERE so.order_id = ? AND s.is_platform = 0 AND so.is_legacy = 0 AND so.status NOT IN ('pending_acceptance','rejected','cancelled')
                AND NOT EXISTS (SELECT 1 FROM seller_disputes d WHERE d.seller_order_id = so.id AND d.status IN ('open','awaiting_seller','awaiting_customer'))
              ORDER BY so.id",
            [$order['id']]
        );
        return array_values(array_filter($rows, fn ($r) => $r['handed_at'] === null || time() <= strtotime((string) $r['handed_at']) + $window));
    }

    // ------------------------------------------------------------------ actions

    /**
     * @param 'customer'|'staff' $role
     * @return array<string,mixed> the dispute
     */
    public function open(int $sellerOrderId, string $role, ?int $userId, string $reason, string $details): array
    {
        if (!isset(self::REASONS[$reason])) {
            throw new ValidationException(['reason' => 'Choose what the problem is.']);
        }
        $details = trim($details);
        if (mb_strlen($details) < 10) {
            throw new ValidationException(['details' => 'Describe the problem in a sentence or two (at least 10 characters).']);
        }
        $d = $this->db->transaction(function () use ($sellerOrderId, $role, $userId, $reason, $details) {
            $so = $this->db->one('SELECT so.*, s.is_platform FROM seller_orders so JOIN sellers s ON s.id = so.seller_id WHERE so.id = ? FOR UPDATE', [$sellerOrderId])
                ?? throw HttpException::notFound();
            $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$so['order_id']]);
            if ((int) $so['is_platform'] === 1 || (int) $so['is_legacy'] === 1) {
                throw ValidationException::withMessage('Items sold by ' . brand_name() . ' are handled by customer care directly.');
            }
            if ($role === 'customer' && !in_array((int) $so['id'], array_map('intval', array_column($this->disputableParts($order), 'id')), true)) {
                throw ValidationException::withMessage('A problem can no longer be reported online for these items. Please contact customer care.');
            }
            if ($this->db->value("SELECT 1 FROM seller_disputes WHERE seller_order_id = ? AND status IN ('open','awaiting_seller','awaiting_customer')", [$sellerOrderId])) {
                throw ValidationException::withMessage('There is already an open case for these items.');
            }
            $id = $this->db->insert('seller_disputes', [
                'dispute_number' => $this->newNumber(), 'order_id' => $so['order_id'], 'seller_order_id' => $sellerOrderId,
                'raised_by' => $userId, 'raised_by_role' => $role, 'reason' => $reason, 'details' => mb_substr($details, 0, 2000),
            ]);
            AuditLogger::log('dispute.opened', 'seller_dispute', $id, null, ['seller_order_id' => $sellerOrderId, 'reason' => $reason, 'by' => $role], $userId);
            return $this->find($id);
        });
        $this->tellSeller($d, 'seller_dispute_opened', "Case {$d['dispute_number']} opened on order {$d['sub_number']}",
            "A case was opened about order {$d['sub_number']}: " . self::REASONS[$reason] . ".\n\n\"" . mb_substr($details, 0, 500) . "\"\n\n"
            . "Please reply in the Seller Centre. Earnings for this order are held until the case is closed.\n" . config('app.url') . '/seller/disputes/' . $d['id']);
        if ($role === 'customer') {
            $this->tellCustomer($d, 'dispute_opened', "We have opened case {$d['dispute_number']} about your items from {$d['seller_name']}. Our team will look into it and keep you updated.");
        }
        if ($ops = (string) Settings::get('store_email', '')) {
            (new NotificationService())->email($ops, "New case {$d['dispute_number']}", "{$d['seller_name']} · order {$d['sub_number']} · " . self::REASONS[$reason]
                . "\n" . config('app.url') . '/admin/disputes/' . $d['id'], 'dispute_opened_staff');
        }
        return $d;
    }

    /** @param 'customer'|'seller'|'staff' $role */
    public function message(int $disputeId, string $role, ?int $userId, string $body, bool $internal = false): void
    {
        $body = trim($body);
        if (mb_strlen($body) < 2) {
            throw new ValidationException(['body' => 'Write a message.']);
        }
        $internal = $internal && $role === 'staff';
        $d = $this->db->transaction(function () use ($disputeId, $role, $userId, $body, $internal) {
            $d = $this->lock($disputeId);
            if (!in_array($d['status'], self::OPEN, true)) {
                throw ValidationException::withMessage('This case is closed. Contact ' . brand_name() . ' customer care if you need more help.');
            }
            $this->db->insert('seller_dispute_messages', ['dispute_id' => $disputeId, 'author_id' => $userId, 'author_role' => $role,
                'body' => mb_substr($body, 0, 2000), 'is_internal' => $internal ? 1 : 0]);
            // A reply from the party Waimart was waiting for puts the case back with Waimart.
            if (($role === 'seller' && $d['status'] === 'awaiting_seller') || ($role === 'customer' && $d['status'] === 'awaiting_customer')) {
                $this->db->exec("UPDATE seller_disputes SET status = 'open' WHERE id = ?", [$disputeId]);
            } else {
                $this->db->exec('UPDATE seller_disputes SET updated_at = NOW() WHERE id = ?', [$disputeId]);
            }
            return $this->find($disputeId);
        });
        if ($internal) {
            return;
        }
        $excerpt = '"' . mb_substr($body, 0, 500) . '"';
        if ($role !== 'seller') {
            $this->tellSeller($d, 'seller_dispute_message', "New message on case {$d['dispute_number']}",
                ($role === 'staff' ? brand_name() : 'The customer') . " wrote about order {$d['sub_number']}:\n\n$excerpt\n\n" . config('app.url') . '/seller/disputes/' . $d['id']);
        }
        if ($role !== 'customer') {
            $this->tellCustomer($d, 'dispute_message', ($role === 'staff' ? brand_name() : $d['seller_name']) . " replied on case {$d['dispute_number']}: $excerpt");
        }
    }

    public function setStatus(int $disputeId, int $staffId, string $status): void
    {
        if (!in_array($status, self::OPEN, true)) {
            throw new ValidationException(['status' => 'Choose a valid status.']);
        }
        $this->db->transaction(function () use ($disputeId, $staffId, $status) {
            $d = $this->lock($disputeId);
            if (!in_array($d['status'], self::OPEN, true)) {
                throw ValidationException::withMessage('This case is already closed.');
            }
            $this->db->exec('UPDATE seller_disputes SET status = ? WHERE id = ?', [$status, $disputeId]);
            AuditLogger::log('dispute.status_changed', 'seller_dispute', $disputeId, ['status' => $d['status']], ['status' => $status], $staffId);
        });
    }

    /**
     * Staff decision. Refund resolutions create a refund obligation for this part (never more than the customer
     * paid for it less earlier refunds) and deduct the seller's share in the ledger.
     */
    public function resolve(int $disputeId, int $staffId, string $resolution, string $note, string $refundAmount = ''): void
    {
        if (!isset(self::RESOLUTIONS[$resolution])) {
            throw new ValidationException(['resolution' => 'Choose a resolution.']);
        }
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new ValidationException(['resolution_note' => 'Explain the decision (both the customer and the seller see this).']);
        }
        $refund = null;
        $d = $this->db->transaction(function () use ($disputeId, $staffId, $resolution, $note, $refundAmount, &$refund) {
            $d = $this->lock($disputeId);
            if (!in_array($d['status'], self::OPEN, true)) {
                throw ValidationException::withMessage('This case is already closed.');
            }
            if (in_array($resolution, ['refund_full', 'refund_partial'], true)) {
                $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$d['order_id']]);
                if (!in_array($order['payment_status'], ['verified', 'cod_collected', 'refund_due', 'refunded'], true)) {
                    throw ValidationException::withMessage('Nothing has been paid for this order yet, so there is nothing to refund.');
                }
                $left = (new RefundService($this->db))->refundableForPart((int) $d['seller_order_id'], true);   // locked until commit
                if ($resolution === 'refund_full') {
                    $refund = $left;
                } else {
                    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($refundAmount)) || Money::cmp(Money::normalize(trim($refundAmount)), '0') <= 0) {
                        throw new ValidationException(['refund_amount' => 'Enter the refund amount.']);
                    }
                    $refund = Money::normalize(trim($refundAmount));
                }
                if (Money::cmp($refund, '0') <= 0 || Money::cmp($refund, $left) > 0) {
                    throw new ValidationException(['refund_amount' => 'The refund can be at most ' . Money::format($left) . ' (what was paid for these items, less earlier refunds).']);
                }
                $refundId = $this->db->insert('refunds', ['order_id' => $d['order_id'], 'seller_order_id' => $d['seller_order_id'], 'dispute_id' => $disputeId,
                    'amount' => $refund, 'reason' => 'Case ' . $d['dispute_number'], 'created_by' => $staffId]);
                $this->db->exec("UPDATE orders SET payment_status = 'refund_due' WHERE id = ?", [$d['order_id']]);
                (new SellerLedgerService($this->db))->refundPart((int) $d['seller_order_id'], $refundId, $refund, $staffId);
            }
            $this->db->exec("UPDATE seller_disputes SET status = 'resolved', resolution = ?, resolution_note = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?",
                [$resolution, mb_substr($note, 0, 1000), $staffId, $disputeId]);
            AuditLogger::log('dispute.resolved', 'seller_dispute', $disputeId, ['status' => $d['status']], ['resolution' => $resolution, 'refund' => $refund, 'note' => $note], $staffId);
            return $this->find($disputeId);
        });
        $outcome = self::RESOLUTIONS[$resolution] . ($refund !== null ? ' (' . Money::format($refund) . ')' : '') . ". $note";
        $this->tellCustomer($d, 'dispute_resolved', "Case {$d['dispute_number']} is resolved: $outcome" . ($refund !== null ? ' Our team will send the refund to you.' : ''));
        $this->tellSeller($d, 'seller_dispute_resolved', "Case {$d['dispute_number']} resolved", "Decision on order {$d['sub_number']}: $outcome"
            . ($refund !== null ? "\n\nThe customer is refunded " . Money::format($refund) . '; the matching share of your sale (less ' . brand_name() . '\'s commission on it) is deducted in your ledger.' : ''));
    }

    /** The customer who raised it withdraws the case. */
    public function withdraw(int $disputeId, int $orderId): void
    {
        $d = $this->db->transaction(function () use ($disputeId, $orderId) {
            $d = $this->lock($disputeId);
            if ((int) $d['order_id'] !== $orderId || $d['raised_by_role'] !== 'customer') {
                throw HttpException::notFound();
            }
            if (!in_array($d['status'], self::OPEN, true)) {
                throw ValidationException::withMessage('This case is already closed.');
            }
            $this->db->exec("UPDATE seller_disputes SET status = 'closed', resolution_note = 'Withdrawn by the customer', resolved_at = NOW() WHERE id = ?", [$disputeId]);
            AuditLogger::log('dispute.withdrawn', 'seller_dispute', $disputeId, ['status' => $d['status']], ['status' => 'closed'], null);
            return $this->find($disputeId);
        });
        $this->tellSeller($d, 'seller_dispute_resolved', "Case {$d['dispute_number']} withdrawn", "The customer withdrew the case on order {$d['sub_number']}. No action is needed.");
    }

    // ------------------------------------------------------------------ reading

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        return $this->db->one(
            'SELECT d.*, so.sub_number, so.seller_id, s.name AS seller_name, o.order_number, o.customer_name
               FROM seller_disputes d JOIN seller_orders so ON so.id = d.seller_order_id JOIN sellers s ON s.id = so.seller_id JOIN orders o ON o.id = d.order_id
              WHERE d.id = ?',
            [$id]
        ) ?? throw HttpException::notFound();
    }

    /** A seller can only ever load disputes on their own parts (404 otherwise). @return array<string,mixed> */
    public function findForSeller(int $sellerId, int $id): array
    {
        $d = $this->find($id);
        if ((int) $d['seller_id'] !== $sellerId) {
            throw HttpException::notFound();
        }
        $d['customer_name'] = self::firstName((string) $d['customer_name']);
        return $d;
    }

    /** @return list<array<string,mixed>> */
    public function messages(int $disputeId, bool $includeInternal): array
    {
        return $this->db->all(
            'SELECT m.*, u.first_name, u.last_name FROM seller_dispute_messages m LEFT JOIN users u ON u.id = m.author_id
              WHERE m.dispute_id = ?' . ($includeInternal ? '' : ' AND m.is_internal = 0') . ' ORDER BY m.id',
            [$disputeId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function list(?int $sellerId, string $filter, ?int $orderId = null, int $limit = 300): array
    {
        $where = [$filter === 'open' ? "d.status IN ('open','awaiting_seller','awaiting_customer')" : ($filter === 'closed' ? "d.status IN ('resolved','closed')" : '1 = 1')];
        $params = [];
        if ($sellerId !== null) {
            $where[] = 'so.seller_id = ?';
            $params[] = $sellerId;
        }
        if ($orderId !== null) {
            $where[] = 'd.order_id = ?';
            $params[] = $orderId;
        }
        $rows = $this->db->all(
            'SELECT d.*, so.sub_number, so.seller_id, s.name AS seller_name, o.order_number, o.customer_name
               FROM seller_disputes d JOIN seller_orders so ON so.id = d.seller_order_id JOIN sellers s ON s.id = so.seller_id JOIN orders o ON o.id = d.order_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(d.status, \'open\', \'awaiting_seller\', \'awaiting_customer\', \'resolved\', \'closed\'), d.updated_at DESC LIMIT ' . max(1, min(1000, $limit)),
            $params
        );
        if ($sellerId !== null) {
            foreach ($rows as &$r) {
                $r['customer_name'] = self::firstName((string) $r['customer_name']);
            }
        }
        return $rows;
    }

    public static function firstName(string $full): string
    {
        return explode(' ', trim($full))[0] ?? '';
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        $this->db->one('SELECT id FROM seller_disputes WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound();
        return $this->find($id);
    }

    private function newNumber(): string
    {
        do {
            $n = 'CS-' . Crypto::code(8);
        } while ($this->db->value('SELECT 1 FROM seller_disputes WHERE dispute_number = ?', [$n]));
        return $n;
    }

    private function tellSeller(array $d, string $template, string $subject, string $body): void
    {
        (new SellerNotifier($this->db))->send((int) $d['seller_id'], $template, $subject, $body, false, ['owner', 'manager'], (int) $d['order_id']);
    }

    private function tellCustomer(array $d, string $template, string $text): void
    {
        try {
            $o = $this->db->one('SELECT o.*, u.notify_email, u.notify_sms FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?', [$d['order_id']]);
            $n = new NotificationService();
            $ctx = ['order_id' => (int) $d['order_id'], 'user_id' => $o['user_id'] ? (int) $o['user_id'] : null];
            if ($o['customer_email'] && ($o['user_id'] === null || (int) $o['notify_email'] === 1)) {
                $n->email((string) $o['customer_email'], "Order {$o['order_number']}: case {$d['dispute_number']}", "Hello {$o['customer_name']},\n\n$text\n\n" . brand_name(), $template, $ctx);
            }
            if ($o['user_id'] === null || (int) $o['notify_sms'] === 1) {
                $n->sms((string) $o['customer_phone'], brand_name() . ": $text", $template, $ctx);
            }
        } catch (\Throwable $e) {
            \Waimart\Core\Logger::error('Dispute notification failed', ['dispute_id' => $d['id'] ?? null, 'error' => $e->getMessage()]);
        }
    }
}
