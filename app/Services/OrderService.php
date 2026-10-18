<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Transactional order placement (C2). In ONE database transaction:
 *   1–3  validate the cart and recalculate prices, discounts and fees on the server (CheckoutService)
 *   4–7  lock inventory rows and recheck available stock (ReservationService::lockAndCheck)
 *   8    create the order, item snapshots and fulfilment snapshot
 *   9–10 create reservations and increment reserved stock (ReservationService::reserve)
 *   11   commit — or roll back everything on any failure (no partial reservations)
 *   12   only then return the confirmation
 *
 * Duplicate submissions (double-click, refresh, network retry) carry the same idempotency key;
 * orders.idempotency_key is UNIQUE, so at most one order — and one set of reservations — exists.
 */
final class OrderService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /**
     * @param array<string,mixed> $in posted checkout form
     * @param array<string,mixed>|null $user
     * @return array{order:array<string,mixed>,access_token:?string,created:bool}
     */
    public function place(CartService $cart, array $in, ?array $user, string $idempotencyKey, string $ip): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $idempotencyKey)) {
            throw ValidationException::withMessage('Your checkout session has expired. Please review your order and try again.');
        }
        $existing = $this->findByKey($idempotencyKey);
        if ($existing !== null) {
            return ['order' => $existing, 'access_token' => null, 'created' => false];
        }

        try {
            [$orderId, $token, $draft] = $this->db->transaction(function () use ($cart, $in, $user, $idempotencyKey, $ip) {
                $draft = (new CheckoutService($this->db))->prepare($cart, $in, $user);

                $reservations = new ReservationService($this->db);
                $reservations->lockAndCheck(array_map(
                    fn ($l) => ['variant_id' => $l['variant_id'], 'quantity' => $l['quantity'], 'name' => $l['name']],
                    $draft['lines']
                ));

                [$status, $paymentStatus, $holdMinutes] = $this->initialState($draft['payment_method']);
                $orderId = $this->db->insert('orders', [
                    'order_number' => $this->newOrderNumber(),
                    'idempotency_key' => $idempotencyKey,
                    'user_id' => $draft['user_id'],
                    'status' => $status,
                    'payment_method' => $draft['payment_method'],
                    'payment_status' => $paymentStatus,
                    'fulfilment_method' => $draft['fulfilment']['method'],
                    'customer_name' => $draft['customer']['name'],
                    'customer_phone' => $draft['customer']['phone'],
                    'customer_email' => $draft['customer']['email'],
                    'subtotal' => $draft['subtotal'],
                    'discount_total' => $draft['discount_total'],
                    'delivery_fee' => $draft['delivery_fee'],
                    'grand_total' => $draft['grand_total'],
                    'coupon_code' => $draft['coupon']['code'] ?? null,
                    'customer_notes' => $draft['notes'],
                    'confirmed_at' => $status === 'confirmed' ? date('Y-m-d H:i:s') : null,
                    'ip_address' => $ip,
                ]);

                // One seller sub-order per seller in the cart, then each item with its sub-order and commission
                // snapshot, then the sub-order totals — all in this same transaction.
                $sellerOrders = new SellerOrderService($this->db);
                $lines = array_map(fn ($l) => $l + ['seller_id' => (int) $this->db->value('SELECT seller_id FROM products WHERE id = ?', [$l['product_id']])], $draft['lines']);
                $subOrders = $sellerOrders->open($orderId, $lines, $draft['user_id']);

                $items = [];
                foreach ($lines as $l) {
                    $itemId = $this->db->insert('order_items', $sellerOrders->itemSnapshot($l['seller_id'], (int) $l['product_id'], (string) $l['line_total']) + [
                        'order_id' => $orderId,
                        // Owning seller at the moment of ordering (snapshot; never changes afterwards).
                        'seller_id' => $l['seller_id'],
                        'seller_order_id' => $subOrders[$l['seller_id']],
                        'product_id' => $l['product_id'],
                        'variant_id' => $l['variant_id'],
                        'sku' => $l['sku'],
                        'product_name' => $l['name'],
                        'variant_name' => $l['variant_name'] ?? 'Default',
                        'image_path' => $l['image'],
                        'unit_price' => $l['price'],
                        'quantity' => $l['quantity'],
                        'line_total' => $l['line_total'],
                    ]);
                    $items[] = ['order_item_id' => $itemId, 'variant_id' => $l['variant_id'], 'quantity' => $l['quantity']];
                }

                $sellerOrders->close($orderId);
                if ($status === 'confirmed') {
                    $sellerOrders->mirrorParent($orderId, 'confirmed', ['type' => 'system'], 'COD order confirmed automatically');
                }

                $this->db->insert('order_fulfilment', $this->fulfilmentSnapshot($orderId, $draft['fulfilment']));

                if ($draft['coupon'] !== null) {
                    (new CouponService($this->db))->redeem((int) $draft['coupon']['id'], $orderId, $draft['user_id'], $draft['customer']['phone'], $draft['discount_total']);
                }

                $reservations->reserve($orderId, $items, $holdMinutes);

                $this->db->insert('order_status_history', [
                    'order_id' => $orderId, 'from_status' => null, 'to_status' => $status,
                    'actor_type' => 'customer', 'actor_user_id' => $draft['user_id'], 'note' => 'Order placed',
                ]);

                $token = Crypto::token(32);
                $this->db->insert('order_access_tokens', ['order_id' => $orderId, 'token_hash' => Crypto::hash($token)]);
                return [$orderId, $token, $draft];
            });
        } catch (\PDOException | ValidationException $e) {
            // A concurrent submission with the same key won the race. Depending on timing we see
            // either the unique-key violation or an already-emptied cart: return the winner's order.
            $isDup = $e instanceof \PDOException && Database::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_order_idem');
            if ($isDup || $e instanceof ValidationException) {
                $existing = $this->findByKey($idempotencyKey);
                if ($existing !== null) {
                    return ['order' => $existing, 'access_token' => null, 'created' => false];
                }
            }
            throw $e;
        }

        // After commit: the order exists regardless of what happens below.
        try {
            $cart->clear();
        } catch (\Throwable $e) {
            Logger::warning('Cart clear failed after order', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
        $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $this->notifyPlaced($order, $token, $draft);
        if ($order['status'] === 'confirmed') {
            (new SellerOrderService($this->db))->notifyConfirmed($orderId);
        }
        return ['order' => $order, 'access_token' => $token, 'created' => true];
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $key): ?array
    {
        return $this->db->one('SELECT * FROM orders WHERE idempotency_key = ?', [$key]);
    }

    /**
     * Initial status per payment method (see ARCHITECTURE §7).
     * @return array{0:string,1:string,2:?int} status, payment status, reservation minutes (null = held)
     */
    private function initialState(string $method): array
    {
        if ($method === 'cod') {
            return Settings::bool('cod_auto_confirm', false)
                ? ['confirmed', 'cod_pending', null]
                : ['pending_confirmation', 'cod_pending', Settings::reservationMinutes('cod')];
        }
        return ['awaiting_payment', 'unpaid', Settings::reservationMinutes($method)];
    }

    private function newOrderNumber(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $n = Brand::orderPrefix() . '-' . Crypto::code(8);   // BM- by default (settings); older orders keep WM-
            if (!$this->db->value('SELECT 1 FROM orders WHERE order_number = ?', [$n])) {
                return $n;
            }
        }
        throw new \RuntimeException('Could not allocate an order number.');
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private function fulfilmentSnapshot(int $orderId, array $f): array
    {
        if ($f['method'] === 'delivery') {
            return [
                'order_id' => $orderId, 'method' => 'delivery',
                'county_name' => $f['county_name'], 'town' => $f['town'], 'location' => $f['location'],
                'delivery_instructions' => $f['instructions'], 'zone_id' => $f['zone_id'], 'zone_name' => $f['zone_name'],
                'eta_min_days' => $f['eta_min'], 'eta_max_days' => $f['eta_max'],
            ];
        }
        return [
            'order_id' => $orderId, 'method' => 'pickup',
            'pickup_point_id' => $f['pickup_point_id'], 'pickup_code' => $f['pickup_code'], 'pickup_name' => $f['pickup_name'],
            'pickup_county' => $f['pickup_county'], 'pickup_town' => $f['pickup_town'], 'pickup_address' => $f['pickup_address'],
            'pickup_map_url' => $f['pickup_map_url'], 'pickup_phone' => $f['pickup_phone'], 'pickup_hours' => $f['pickup_hours'],
            'pickup_instructions' => $f['pickup_instructions'],
        ];
    }

    /** "Order successfully placed" (F) — never claims payment or readiness. */
    private function notifyPlaced(array $order, string $token, array $draft): void
    {
        try {
            $user = $order['user_id'] ? $this->db->one('SELECT notify_email, notify_sms FROM users WHERE id = ?', [$order['user_id']]) : null;
            $link = rtrim((string) config('app.url'), '/') . '/track/' . $order['order_number'] . '?t=' . $token;
            $next = OrderPresenter::nextStepText($order, (new ReservationService($this->db))->expiresAt((int) $order['id']));
            $body = "Hello {$order['customer_name']},\n\nThank you for shopping with " . brand_name() . ". Your order {$order['order_number']} has been placed.\n"
                . 'Total: ' . Money::format((string) $order['grand_total']) . "\n\n$next\n\nTrack your order: $link\n\n" . brand_name();
            $n = new NotificationService();
            $ctx = ['user_id' => $order['user_id'] ? (int) $order['user_id'] : null, 'order_id' => (int) $order['id'], 'secrets' => [$token]];
            if ($order['customer_email'] && ($user === null || (int) $user['notify_email'] === 1)) {
                $n->email((string) $order['customer_email'], "Order {$order['order_number']} placed", $body, 'order_placed', $ctx);
            }
            if ($user === null || (int) $user['notify_sms'] === 1) {
                $n->sms((string) $order['customer_phone'], brand_name() . ": order {$order['order_number']} placed. " . $next . " Track: $link", 'order_placed', $ctx);
            }
        } catch (\Throwable $e) {
            Logger::error('Order placed notification failed', ['order_id' => $order['id'], 'error' => $e->getMessage()]);
        }
    }
}
