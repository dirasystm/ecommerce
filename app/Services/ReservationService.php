<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;

/**
 * Stock reservations (C2, C3). Must run inside the checkout transaction.
 *
 *   lockAndCheck()  — C2 steps 5–7: lock inventory rows (fixed variant order → no deadlocks)
 *                     and recheck availability under the lock.
 *   reserve()       — C2 steps 9–10: one reservation row per order item, reserved counter
 *                     incremented and a 'reserve' ledger entry, all in the same transaction.
 *
 * Release, expiry and consumption are added in Phase 7.
 */
final class ReservationService
{
    private Database $db;
    private InventoryService $inventory;

    /**
     * TEST-ONLY fault injection (C9 scenario 10): called before each reservation insert.
     * Ignored unless app.env is "testing".
     * @var (callable(int $variantId, int $index): void)|null
     */
    public static $faultInjector = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
        $this->inventory = new InventoryService($this->db);
    }

    /**
     * @param list<array{variant_id:int,quantity:int,name:string}> $lines
     * @throws InsufficientStockException
     */
    public function lockAndCheck(array $lines): void
    {
        $this->assertInTransaction();
        $wanted = [];
        $names = [];
        foreach ($lines as $l) {
            $wanted[$l['variant_id']] = ($wanted[$l['variant_id']] ?? 0) + $l['quantity'];
            $names[$l['variant_id']] = $l['name'];
        }
        ksort($wanted);    // always lock in ascending variant order
        $ids = array_keys($wanted);
        $rows = $this->db->all(
            'SELECT variant_id, on_hand, reserved FROM inventory WHERE variant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY variant_id FOR UPDATE',
            $ids
        );
        $levels = array_column($rows, null, 'variant_id');
        $shortages = [];
        foreach ($wanted as $vid => $qty) {
            if (!isset($levels[$vid])) {
                throw new \RuntimeException("Inventory record missing for variant $vid.");
            }
            $available = (int) $levels[$vid]['on_hand'] - (int) $levels[$vid]['reserved'];
            if ($available < $qty) {
                $shortages[] = ['variant_id' => $vid, 'name' => $names[$vid], 'requested' => $qty, 'available' => max(0, $available)];
            }
        }
        if ($shortages) {
            throw new InsufficientStockException($shortages);
        }
    }

    /**
     * Creates reservations for an order's items. Rows must already be locked by lockAndCheck().
     *
     * @param list<array{order_item_id:int,variant_id:int,quantity:int}> $items
     * @param int|null $minutes expiry window; null = held until fulfilment (confirmed order)
     * @return list<int> reservation ids
     */
    public function reserve(int $orderId, array $items, ?int $minutes): array
    {
        $this->assertInTransaction();
        usort($items, fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']);
        $ids = [];
        foreach ($items as $index => $it) {
            if (self::$faultInjector !== null && App::env() === 'testing') {
                (self::$faultInjector)($it['variant_id'], $index);
            }
            $this->db->exec(
                'INSERT INTO stock_reservations (order_id, order_item_id, variant_id, quantity, expires_at)
                 VALUES (?, ?, ?, ?, ' . ($minutes === null ? 'NULL' : 'NOW() + INTERVAL ? MINUTE') . ')',
                $minutes === null
                    ? [$orderId, $it['order_item_id'], $it['variant_id'], $it['quantity']]
                    : [$orderId, $it['order_item_id'], $it['variant_id'], $it['quantity'], $minutes]
            );
            $reservationId = (int) $this->db->pdo()->lastInsertId();
            // The CHECK (reserved <= on_hand) constraint is a final safety net against oversell.
            $this->inventory->applyChange($it['variant_id'], 0, $it['quantity'], 'reserve', [
                'actor_type' => 'customer',
                'reservation_id' => $reservationId,
                'order_id' => $orderId,
                'note' => 'Reserved at order placement',
            ]);
            $ids[] = $reservationId;
        }
        return $ids;
    }

    /**
     * Releases (or expires) every ACTIVE reservation of an order, exactly once each (C4, C6).
     * Guarded update `WHERE status = 'active'` + UNIQUE(reservation_id, movement_type) on the ledger
     * make repeated or concurrent calls harmless: already-processed rows are skipped.
     *
     * @param 'released'|'expired' $as
     * @return int number of reservations released by THIS call
     */
    public function releaseForOrder(int $orderId, string $as, string $reason, string $actorType, ?int $actorId = null, ?int $sellerOrderId = null): int
    {
        $this->assertInTransaction();
        if (!in_array($as, ['released', 'expired'], true)) {
            throw new \InvalidArgumentException('Invalid release state.');
        }
        $count = 0;
        foreach ($this->activeFor($orderId, $sellerOrderId) as $r) {
            $this->inventory->lock((int) $r['variant_id']);                      // ascending variant order
            $changed = $this->db->exec(
                "UPDATE stock_reservations SET status = ?, released_at = NOW(), release_reason = ? WHERE id = ? AND status = 'active'",
                [$as, mb_substr($reason, 0, 100), $r['id']]
            );
            if ($changed !== 1) {
                continue;                                                          // someone else got there first
            }
            // Ledger: short reason code + free-text note (the reason column holds codes only).
            $this->inventory->applyChange((int) $r['variant_id'], 0, -(int) $r['quantity'], $as === 'expired' ? 'expire' : 'release', [
                'actor_type' => $actorType, 'actor_user_id' => $actorId, 'reservation_id' => (int) $r['id'],
                'order_id' => $orderId, 'reason' => $as === 'expired' ? 'expired' : 'cancelled', 'note' => $reason,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Converts reserved stock into a physical deduction at hand-over (C7): on_hand −= qty and
     * reserved −= qty for every reservation, exactly once. Refuses unless EVERY order item has an
     * active reservation (expired / released / already-consumed stock is never consumed).
     */
    public function consumeForOrder(int $orderId, string $actorType, ?int $actorId, string $reason, ?int $sellerOrderId = null): int
    {
        $this->assertInTransaction();
        $active = $this->activeFor($orderId, $sellerOrderId);
        $items = $sellerOrderId === null
            ? (int) $this->db->value('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$orderId])
            : (int) $this->db->value('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND seller_order_id = ?', [$orderId, $sellerOrderId]);
        if ($items === 0 || count($active) !== $items) {
            throw new ReservationStateException(
                'This order no longer has reserved stock for every item (it may have expired, been released or already been handed over).'
            );
        }
        foreach ($active as $r) {
            $this->inventory->lock((int) $r['variant_id']);
            $changed = $this->db->exec(
                "UPDATE stock_reservations SET status = 'consumed', consumed_at = NOW(), expires_at = NULL WHERE id = ? AND status = 'active'",
                [$r['id']]
            );
            if ($changed !== 1) {
                throw new ReservationStateException('Reservation was changed by another process. Please reload and try again.');
            }
            $qty = (int) $r['quantity'];
            $this->inventory->applyChange((int) $r['variant_id'], -$qty, -$qty, 'consume', [
                'actor_type' => $actorType, 'actor_user_id' => $actorId, 'reservation_id' => (int) $r['id'],
                'order_id' => $orderId, 'reason' => 'fulfilment', 'note' => $reason,
            ]);
            $this->db->exec('UPDATE order_items SET fulfilled_qty = fulfilled_qty + ? WHERE id = ?', [$qty, $r['order_item_id']]);
        }
        return count($active);
    }

    /** On confirmation the stock is held until fulfilment: active reservations stop expiring. */
    public function holdUntilFulfilment(int $orderId): int
    {
        $this->assertInTransaction();
        return $this->db->exec("UPDATE stock_reservations SET expires_at = NULL WHERE order_id = ? AND status = 'active'", [$orderId]);
    }

    /** True when every item of the order is covered by an active reservation. */
    public function fullyReserved(int $orderId): bool
    {
        $items = (int) $this->db->value('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$orderId]);
        $active = (int) $this->db->value("SELECT COUNT(*) FROM stock_reservations WHERE order_id = ? AND status = 'active'", [$orderId]);
        return $items > 0 && $items === $active;
    }

    /** @return list<array<string,mixed>> active reservations, locked, in ascending variant order */
    private function activeFor(int $orderId, ?int $sellerOrderId = null): array
    {
        if ($sellerOrderId !== null) {
            return $this->db->all(
                "SELECT r.* FROM stock_reservations r JOIN order_items oi ON oi.id = r.order_item_id
                  WHERE r.order_id = ? AND oi.seller_order_id = ? AND r.status = 'active' ORDER BY r.variant_id, r.id FOR UPDATE",
                [$orderId, $sellerOrderId]
            );
        }
        return $this->db->all(
            "SELECT * FROM stock_reservations WHERE order_id = ? AND status = 'active' ORDER BY variant_id, id FOR UPDATE",
            [$orderId]
        );
    }

    /** Releases only one seller sub-order's reservations (seller rejection / part cancellation), exactly once each. */
    public function releaseForSellerOrder(int $orderId, int $sellerOrderId, string $reason, string $actorType, ?int $actorId = null): int
    {
        return $this->releaseForOrder($orderId, 'released', $reason, $actorType, $actorId, $sellerOrderId);
    }

    /** Consumes one seller sub-order's reservations (hand-over / dispatch / collection of that part). */
    public function consumeForSellerOrder(int $orderId, int $sellerOrderId, string $actorType, ?int $actorId, string $reason): int
    {
        return $this->consumeForOrder($orderId, $actorType, $actorId, $reason, $sellerOrderId);
    }

    /** Earliest active expiry for an order (null when held until fulfilment or none active). */
    public function expiresAt(int $orderId): ?string
    {
        $v = $this->db->value("SELECT MIN(expires_at) FROM stock_reservations WHERE order_id = ? AND status = 'active'", [$orderId]);
        return $v === null ? null : (string) $v;
    }

    private function assertInTransaction(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Reservations must be created inside the checkout transaction.');
        }
    }
}
