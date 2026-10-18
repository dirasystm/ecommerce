<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\RateLimiter;
use Waimart\Core\ValidationException;

/**
 * Processes due reservations (C8). Safe to run repeatedly and concurrently:
 *   - a MariaDB named lock (GET_LOCK) lets only one worker run at a time
 *     (MariaDB 10.4 has no SKIP LOCKED);
 *   - each order is handled in its own transaction with the order row locked;
 *   - every reservation transition is a guarded `WHERE status = 'active'` update, so even
 *     without the named lock nothing is released twice.
 */
final class ExpiryService
{
    public const LOCK_NAME = 'waimart_expiry';
    /** Orders that have not been confirmed — their reservations may expire (C4/C5). */
    public const EXPIRABLE = ['awaiting_payment', 'awaiting_verification', 'pending_confirmation'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /**
     * @return array{locked:bool,orders_expired:int,reservations_released:int,errors:int,carts_purged:int}
     */
    public function run(int $limit = 200): array
    {
        $stats = ['locked' => false, 'orders_expired' => 0, 'reservations_released' => 0, 'errors' => 0, 'carts_purged' => 0];
        if ((int) $this->db->value('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]) !== 1) {
            $stats['locked'] = true;          // another worker is running; exit quietly
            return $stats;
        }
        try {
            $due = $this->db->all(
                "SELECT order_id, MIN(expires_at) AS due FROM stock_reservations
                  WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW()
                  GROUP BY order_id ORDER BY due LIMIT " . max(1, min(1000, $limit))
            );
            $workflow = new OrderWorkflow($this->db);
            foreach ($due as $row) {
                $orderId = (int) $row['order_id'];
                try {
                    $before = (int) $this->db->value("SELECT COUNT(*) FROM stock_reservations WHERE order_id = ? AND status = 'active'", [$orderId]);
                    $status = (string) $this->db->value('SELECT status FROM orders WHERE id = ?', [$orderId]);
                    if (in_array($status, self::EXPIRABLE, true)) {
                        $order = $workflow->transition($orderId, 'expired', ['type' => 'system'], 'Reservation expired before the order was confirmed');
                        $stats['orders_expired']++;
                        $stats['reservations_released'] += $before;
                        OrderNotifier::statusChanged($order);
                    } else {
                        // Defensive: a reservation with an expiry on an order that is not expirable
                        // (e.g. a re-reservation awaiting staff resolution) — release only what is due.
                        $stats['reservations_released'] += $this->releaseDueOnly($orderId);
                    }
                } catch (ValidationException) {
                    // Order changed state between selection and lock (e.g. just confirmed): nothing to do.
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Logger::error('Reservation expiry failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
                }
            }
            $stats['carts_purged'] = $this->housekeeping();
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
        return $stats;
    }

    /** Fallback for environments without the scheduler: at most one small sweep per minute (C8). */
    public static function maybeSweep(): void
    {
        try {
            if ((new RateLimiter(App::db()))->attempt('expiry-sweep|global', 1, 60)) {
                (new self())->run(20);
            }
        } catch (\Throwable $e) {
            Logger::warning('Opportunistic expiry sweep failed', ['error' => $e->getMessage()]);
        }
    }

    private function releaseDueOnly(int $orderId): int
    {
        return $this->db->transaction(function () use ($orderId) {
            $this->db->one('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            $inv = new InventoryService($this->db);
            $n = 0;
            $rows = $this->db->all(
                "SELECT * FROM stock_reservations WHERE order_id = ? AND status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW()
                  ORDER BY variant_id FOR UPDATE",
                [$orderId]
            );
            foreach ($rows as $r) {
                $inv->lock((int) $r['variant_id']);
                if ($this->db->exec("UPDATE stock_reservations SET status = 'expired', released_at = NOW(), release_reason = 'Reservation expired' WHERE id = ? AND status = 'active'", [$r['id']]) === 1) {
                    $inv->applyChange((int) $r['variant_id'], 0, -(int) $r['quantity'], 'expire', [
                        'actor_type' => 'system', 'reservation_id' => (int) $r['id'], 'order_id' => $orderId, 'reason' => 'expired', 'note' => 'Reservation expired',
                    ]);
                    $n++;
                }
            }
            return $n;
        });
    }

    /** Purges abandoned guest carts (30 days) and stale rate-limit windows. */
    private function housekeeping(): int
    {
        $carts = $this->db->exec('DELETE FROM carts WHERE user_id IS NULL AND updated_at < NOW() - INTERVAL 30 DAY LIMIT 500');
        $this->db->exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY LIMIT 1000');
        return $carts;
    }
}
