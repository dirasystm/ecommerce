<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\RateLimiter;
use Waimart\Core\ValidationException;

/**
 * Pickup collection (B4). Only an authorised staff member who enters the customer's valid
 * collection code (and confirms the ID check) can mark an order collected. Attempts are
 * rate-limited per order and per staff member, every failure is audited, and the code is
 * invalidated on collection or cancellation.
 */
final class CollectionService
{
    public const MAX_ATTEMPTS_PER_ORDER = 5;      // per 15 minutes
    public const MAX_ATTEMPTS_PER_STAFF = 30;     // per 15 minutes
    private const WINDOW = 900;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** Marks a pickup order ready and sends the customer their code. @return array<string,mixed> */
    public function markReady(int $orderId, int $staffId): array
    {
        $order = (new OrderWorkflow($this->db))->transition($orderId, 'ready_for_collection', ['type' => 'staff', 'id' => $staffId], 'Ready for collection');
        OrderNotifier::statusChanged($order);
        return $order;
    }

    /**
     * @return array<string,mixed> the collected order
     * @throws ValidationException on wrong/expired code or when rate-limited
     */
    public function verifyAndCollect(int $orderId, string $code, int $staffId, bool $idChecked, ?string $note = null): array
    {
        if (!$idChecked) {
            throw new ValidationException(['id_checked' => 'Confirm that you have checked the customer\'s ID or authorisation.']);
        }
        $rl = new RateLimiter($this->db);
        if ($rl->tooMany('collect-order|' . $orderId, self::MAX_ATTEMPTS_PER_ORDER, self::WINDOW)
            || !$rl->attempt('collect-staff|' . $staffId, self::MAX_ATTEMPTS_PER_STAFF, self::WINDOW)) {
            AuditLogger::log('collection.rate_limited', 'order', $orderId, null, null, $staffId);
            throw ValidationException::withMessage('Too many incorrect codes for this order. Wait 15 minutes, or ask a manager to re-issue the code.');
        }

        $f = $this->db->one(
            'SELECT f.*, o.status, o.fulfilment_method FROM order_fulfilment f JOIN orders o ON o.id = f.order_id WHERE f.order_id = ?',
            [$orderId]
        ) ?? throw ValidationException::withMessage('Order not found.');
        $marketplace = SellerOrderService::hasMarketplaceSeller($orderId, $this->db);
        $readyParts = $marketplace ? array_map('intval', array_column($this->db->all(
            "SELECT id FROM seller_orders WHERE order_id = ? AND status = 'ready_for_collection' ORDER BY id", [$orderId]), 'id')) : [];
        if ($f['fulfilment_method'] !== 'pickup' || ($marketplace ? $readyParts === [] : $f['status'] !== 'ready_for_collection')) {
            throw ValidationException::withMessage('This order is not ready for collection.');
        }
        $normalized = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        $valid = $f['collection_code_status'] === 'active'
            && $f['collection_code_hash'] !== null
            && $normalized !== ''
            && hash_equals((string) $f['collection_code_hash'], Crypto::hash($normalized));

        if (!$valid) {
            $rl->attempt('collect-order|' . $orderId, self::MAX_ATTEMPTS_PER_ORDER, self::WINDOW);
            AuditLogger::log('collection.code_failed', 'order', $orderId, null, ['code_status' => $f['collection_code_status']], $staffId);
            throw new ValidationException(['code' => 'That collection code is not valid for this order.']);
        }

        if ($marketplace) {
            // Hand over every part that is ready now; the code stays valid for parts still on their way.
            foreach ($readyParts as $partId) {
                (new SellerOrderWorkflow($this->db))->markCollectionVerified()->transition($partId, 'collected', ['type' => 'staff', 'id' => $staffId],
                    ['note' => 'Collected — code verified, ID checked' . ($note ? ': ' . $note : '')]);
            }
            $rl->clear('collect-order|' . $orderId);
            AuditLogger::log('collection.parts_collected', 'order', $orderId, null, ['parts' => $readyParts], $staffId);
            return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        }
        $order = (new OrderWorkflow($this->db))->markCollectionVerified()->transition(
            $orderId, 'collected', ['type' => 'staff', 'id' => $staffId],
            'Collected — code verified, ID checked' . ($note ? ': ' . $note : ''),
            ['outcome' => 'Collected; code verified; ID checked' . ($note ? '; ' . $note : '')]
        );
        $rl->clear('collect-order|' . $orderId);
        OrderNotifier::statusChanged($order);
        return $order;
    }

    /** Re-issues a lost/compromised code (old code stops working). @return array<string,mixed> */
    public function reissueCode(int $orderId, int $staffId): array
    {
        $order = $this->db->transaction(function () use ($orderId, $staffId) {
            $o = $this->db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]) ?? throw ValidationException::withMessage('Order not found.');
            if ($o['status'] !== 'ready_for_collection' && !self::anyPartReady($orderId)) {
                throw ValidationException::withMessage('Codes can only be re-issued while an order is ready for collection.');
            }
            $code = Crypto::code(8);
            $this->db->exec(
                "UPDATE order_fulfilment SET collection_code_hash = ?, collection_code_enc = ?, collection_code_status = 'active' WHERE order_id = ?",
                [Crypto::hash($code), Crypto::encrypt($code), $orderId]
            );
            AuditLogger::log('collection.code_reissued', 'order', $orderId, null, null, $staffId);
            return $o;
        });
        (new RateLimiter($this->db))->clear('collect-order|' . $orderId);
        OrderNotifier::statusChanged($order);     // resends the (new) code
        return $order;
    }

    /** A marketplace order with at least one part waiting at the pickup station. */
    public static function anyPartReady(int $orderId): bool
    {
        return (bool) App::db()->value("SELECT 1 FROM seller_orders WHERE order_id = ? AND status = 'ready_for_collection' LIMIT 1", [$orderId]);
    }

    /** The plain code for display to an authorised viewer, or null when not applicable. */
    public static function codeFor(array $order): ?string
    {
        if (($order['status'] ?? '') !== 'ready_for_collection' && !self::anyPartReady((int) ($order['id'] ?? 0))) {
            return null;
        }
        $f = App::db()->one('SELECT collection_code_enc, collection_code_status FROM order_fulfilment WHERE order_id = ?', [$order['id']]);
        if ($f === null || $f['collection_code_status'] !== 'active' || $f['collection_code_enc'] === null) {
            return null;
        }
        return Crypto::decrypt((string) $f['collection_code_enc']);
    }
}
