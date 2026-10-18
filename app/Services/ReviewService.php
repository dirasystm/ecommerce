<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Verified-purchase reviews (D2). Only the signed-in owner of an order that was delivered or
 * collected can review its items, once per item (DB-unique). Reviews are moderated, and product
 * ratings are computed from approved reviews only.
 */
final class ReviewService
{
    private const ELIGIBLE = ['delivered', 'collected', 'return_requested', 'returned'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,mixed>|null the order item if this user may review it */
    public function reviewableItem(int $userId, int $orderItemId): ?array
    {
        return $this->db->one(
            'SELECT oi.*, o.order_number FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.id = ? AND o.user_id = ? AND o.status IN (' . self::list() . ') AND oi.fulfilled_qty > 0
                AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_item_id = oi.id)',
            [$orderItemId, $userId]
        );
    }

    /** @param array<string,mixed> $in */
    public function submit(int $userId, int $orderItemId, array $in): int
    {
        Validator::validate($in, ['rating' => 'required|int|gte:1|lte:5', 'title' => 'nullable|max:120', 'body' => 'nullable|max:2000']);
        $item = $this->reviewableItem($userId, $orderItemId);
        if ($item === null) {
            throw ValidationException::withMessage('You can review only items you have received, once per item.');
        }
        try {
            return $this->db->insert('reviews', [
                'product_id' => $item['product_id'], 'user_id' => $userId, 'order_item_id' => $orderItemId,
                'rating' => (int) $in['rating'],
                'title' => trim((string) ($in['title'] ?? '')) ?: null,
                'body' => trim((string) ($in['body'] ?? '')) ?: null,
            ]);
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw ValidationException::withMessage('You have already reviewed this item.');
            }
            throw $e;
        }
    }

    /** @param 'approved'|'rejected' $decision */
    public function moderate(int $reviewId, string $decision, int $staffId, ?string $note): void
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessage('Invalid decision.');
        }
        $this->db->transaction(function () use ($reviewId, $decision, $staffId, $note) {
            $r = $this->db->one('SELECT * FROM reviews WHERE id = ? FOR UPDATE', [$reviewId]) ?? throw ValidationException::withMessage('Review not found.');
            $this->db->exec('UPDATE reviews SET status = ?, moderated_by = ?, moderated_at = NOW(), moderation_note = ? WHERE id = ?',
                [$decision, $staffId, $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, $reviewId]);
            $this->recalculate((int) $r['product_id']);
            AuditLogger::log('review.' . $decision, 'review', $reviewId, ['status' => $r['status']], ['status' => $decision, 'note' => $note], $staffId);
        });
    }

    public function recalculate(int $productId): void
    {
        $this->db->exec(
            "UPDATE products p SET
                rating_count = (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved'),
                rating_avg = COALESCE((SELECT ROUND(AVG(r.rating), 2) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved'), 0)
              WHERE p.id = ?",
            [$productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function approvedFor(int $productId, int $limit = 20): array
    {
        return $this->db->all(
            "SELECT r.rating, r.title, r.body, r.created_at, u.first_name, LEFT(u.last_name, 1) AS last_initial, oi.variant_name
               FROM reviews r JOIN users u ON u.id = r.user_id JOIN order_items oi ON oi.id = r.order_item_id
              WHERE r.product_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC LIMIT " . max(1, min(100, $limit)),
            [$productId]
        );
    }

    /** Order items of this user awaiting a review. @return list<array<string,mixed>> */
    public function pendingForUser(int $userId): array
    {
        return $this->db->all(
            'SELECT oi.id, oi.product_name, oi.variant_name, oi.image_path, o.order_number, o.completed_at FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
              WHERE o.user_id = ? AND o.status IN (' . self::list() . ') AND oi.fulfilled_qty > 0
                AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_item_id = oi.id)
              ORDER BY o.completed_at DESC LIMIT 50',
            [$userId]
        );
    }

    private static function list(): string
    {
        return "'" . implode("','", self::ELIGIBLE) . "'";
    }
}
