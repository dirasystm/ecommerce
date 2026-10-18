<?php

declare(strict_types=1);

namespace Waimart\Repositories;

use Waimart\Core\Database;

/** Read side for order pages (confirmation, tracking, account history). */
final class OrderRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function byNumber(string $number): ?array
    {
        if (!preg_match('/^WM-[A-Z0-9]{8}$/', $number)) {
            return null;
        }
        return $this->db->one('SELECT * FROM orders WHERE order_number = ?', [$number]);
    }

    /** @return array<string,mixed> order + items, fulfilment, history, reservation expiry */
    public function details(array $order): array
    {
        $id = (int) $order['id'];
        $order['items'] = $this->db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
        // Seller groups (one per seller sub-order), each with its own items and progress.
        $order['groups'] = (new \Waimart\Services\SellerOrderService($this->db))->forOrder($id);
        $order['fulfilment'] = $this->db->one('SELECT * FROM order_fulfilment WHERE order_id = ?', [$id]) ?? [];
        $order['history'] = $this->db->all(
            'SELECT to_status, note, created_at FROM order_status_history WHERE order_id = ? ORDER BY id',
            [$id]
        );
        $order['payments'] = $this->db->all(
            'SELECT s.id, s.transaction_code, s.amount_claimed, s.status, s.submitted_at, s.payer_phone, v.note AS decision_note, v.created_at AS decided_at
               FROM payment_submissions s LEFT JOIN payment_verifications v ON v.submission_id = s.id
              WHERE s.order_id = ? ORDER BY s.id',
            [$id]
        );
        $order['reservation_expires_at'] = $this->db->value(
            "SELECT MIN(expires_at) FROM stock_reservations WHERE order_id = ? AND status = 'active'",
            [$id]
        );
        return $order;
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT o.order_number, o.status, o.payment_status, o.grand_total, o.placed_at, o.fulfilment_method,
                    (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) AS item_count,
                    (SELECT image_path FROM order_items WHERE order_id = o.id ORDER BY id LIMIT 1) AS image
               FROM orders o WHERE o.user_id = ? ORDER BY o.placed_at DESC, o.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$userId]
        );
    }
}
