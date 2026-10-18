<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;

/**
 * Coupon evaluation and redemption. Discounts are computed on the server from the
 * merchandise subtotal and never exceed it. Usage limits are checked under a row lock.
 */
final class CouponService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /**
     * @param bool $lock lock the coupon row (use inside the order transaction)
     * @return array{coupon:array<string,mixed>,discount:string}
     */
    public function evaluate(string $code, string $subtotal, ?int $userId, string $phone, bool $lock = false): array
    {
        $code = strtoupper(trim($code));
        $c = $code === '' ? null : $this->db->one('SELECT * FROM coupons WHERE code = ?' . ($lock ? ' FOR UPDATE' : ''), [$code]);
        $now = time();
        if ($c === null || !(int) $c['is_active']
            || ($c['starts_at'] !== null && strtotime((string) $c['starts_at']) > $now)
            || ($c['ends_at'] !== null && strtotime((string) $c['ends_at']) <= $now)) {
            throw new ValidationException(['coupon_code' => 'This coupon code is not valid.']);
        }
        if ($c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']) {
            throw new ValidationException(['coupon_code' => 'This coupon has reached its usage limit.']);
        }
        if (Money::cmp($subtotal, (string) $c['min_order_amount']) < 0) {
            throw new ValidationException(['coupon_code' => 'This coupon needs a minimum order of ' . Money::format((string) $c['min_order_amount']) . '.']);
        }
        $used = (int) $this->db->value(
            "SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND status = 'active' AND (customer_phone = ?" . ($userId ? ' OR user_id = ?' : '') . ')',
            $userId ? [$c['id'], $phone, $userId] : [$c['id'], $phone]
        );
        if ($used >= (int) $c['per_customer_limit']) {
            throw new ValidationException(['coupon_code' => 'You have already used this coupon.']);
        }
        $discount = $c['discount_type'] === 'percent'
            ? Money::percent($subtotal, (string) $c['discount_value'])
            : Money::normalize((string) $c['discount_value']);
        if ($c['max_discount'] !== null) {
            $discount = Money::min($discount, (string) $c['max_discount']);
        }
        return ['coupon' => $c, 'discount' => Money::min($discount, $subtotal)];
    }

    /** Records a redemption (inside the order transaction, after evaluate(..., lock: true)). */
    public function redeem(int $couponId, int $orderId, ?int $userId, string $phone, string $discount): void
    {
        $this->db->insert('coupon_redemptions', [
            'coupon_id' => $couponId, 'order_id' => $orderId, 'user_id' => $userId,
            'customer_phone' => $phone, 'discount_amount' => $discount,
        ]);
        $this->db->exec('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$couponId]);
    }
}
