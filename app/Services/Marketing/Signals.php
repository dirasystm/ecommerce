<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Logger;
use Waimart\Core\Request;
use Waimart\Core\Session;

/**
 * The hooks the shop calls at each step (one line in each controller). They translate shop actions into tracked
 * events, keep the recommendation placement a product was found through, and carry campaign attribution onto orders.
 * Nothing here can break the action that called it.
 */
final class Signals
{
    /** Session key: product id => recommendation placement it was opened from. */
    private const REC_SOURCE = 'rec_src';

    /** @param array<string,mixed> $product from ProductRepository::findActiveBySlug */
    public static function productViewed(Request $req, array $product): void
    {
        $base = ['product_id' => (int) $product['id'], 'category_id' => (int) $product['category_id'],
                 'brand_id' => $product['brand_id'] !== null ? (int) $product['brand_id'] : null, 'seller_id' => (int) $product['seller_id']];
        Tracker::record('product_viewed', $base);
        $placement = (string) ($req->query['rec'] ?? '');
        if (isset(Recommender::PLACEMENTS[$placement])) {
            Tracker::record('rec_click', $base + ['placement' => $placement]);
            $src = (array) Session::get(self::REC_SOURCE, []);
            $src[(int) $product['id']] = $placement;
            Session::set(self::REC_SOURCE, array_slice($src, -40, null, true));
            return;
        }
        // Chosen from search results on this site: remember the search words.
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === $host && str_contains((string) parse_url($ref, PHP_URL_PATH), '/search')) {
            parse_str((string) parse_url($ref, PHP_URL_QUERY), $q);
            if (trim((string) ($q['q'] ?? '')) !== '') {
                Tracker::record('search_click', $base + ['query' => (string) $q['q']]);
            }
        }
    }

    public static function cart(string $event, int $variantId): void
    {
        try {
            $p = App::db()->one('SELECT p.id, p.category_id, p.brand_id, p.seller_id FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$variantId]);
            if ($p === null) {
                return;
            }
            $src = (array) Session::get(self::REC_SOURCE, []);
            Tracker::record($event, ['product_id' => (int) $p['id'], 'category_id' => (int) $p['category_id'], 'seller_id' => (int) $p['seller_id'],
                'brand_id' => $p['brand_id'] !== null ? (int) $p['brand_id'] : null, 'placement' => $src[(int) $p['id']] ?? null]);
        } catch (\Throwable $e) {
            Logger::error('Cart signal failed', ['error' => $e->getMessage()]);
        }
    }

    public static function wishlist(bool $saved, int $productId, int $userId): void
    {
        try {
            if ($saved) {
                // The real current price when saved: price-drop alerts compare against it (never an invented "was" price).
                App::db()->exec('UPDATE wishlist_items SET price_at_add = (SELECT COALESCE(MIN(v.price), NULL) FROM product_variants v WHERE v.default_product_key = ?)
                                  WHERE user_id = ? AND product_id = ?', [$productId, $userId, $productId]);
            }
            Tracker::record($saved ? 'wishlist_added' : 'wishlist_removed', ['product_id' => $productId, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            Logger::error('Wishlist signal failed', ['error' => $e->getMessage()]);
        }
    }

    /** A placed order: the order itself (with the campaign that led to it) and sales from recommendation placements. @param array<string,mixed> $order */
    public static function orderPlaced(array $order): void
    {
        (new CartRecovery())->orderPlaced($order);    // independent of activity tracking consent
        try {
            $uid = $order['user_id'] !== null ? (int) $order['user_id'] : null;
            Tracker::record('order_placed', ['user_id' => $uid, 'order_id' => (int) $order['id'], 'amount' => (string) $order['grand_total']]);
            $src = (array) Session::get(self::REC_SOURCE, []);
            if ($src) {
                foreach (App::db()->all('SELECT product_id, line_total FROM order_items WHERE order_id = ?', [$order['id']]) as $it) {
                    if (isset($src[(int) $it['product_id']])) {
                        Tracker::record('rec_order', ['user_id' => $uid, 'order_id' => (int) $order['id'], 'product_id' => (int) $it['product_id'],
                            'placement' => $src[(int) $it['product_id']], 'amount' => (string) $it['line_total']]);
                    }
                }
                Session::forget(self::REC_SOURCE);
            }
            if ($uid !== null) {
                App::db()->exec('UPDATE marketing_leads SET last_seen_at = NOW() WHERE user_id = ?', [$uid]);
            }
        } catch (\Throwable $e) {
            Logger::error('Order signal failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Later order outcomes, credited to the same campaign links as the placed order (whoever changed the status).
     * @param array<string,mixed> $order
     */
    public static function orderStatus(array $order): void
    {
        $event = match ((string) $order['status']) {
            'confirmed' => 'order_confirmed', 'delivered', 'collected' => 'order_delivered',
            'cancelled', 'expired' => 'order_cancelled', 'returned' => 'order_returned', default => null,
        };
        if ($event === null) {
            return;
        }
        try {
            $db = App::db();
            if ($db->value('SELECT id FROM shop_events WHERE order_id = ? AND event = ? LIMIT 1', [$order['id'], $event])) {
                return;     // counted once per order
            }
            $placed = $db->one("SELECT link_id, first_link_id FROM shop_events WHERE order_id = ? AND event = 'order_placed' LIMIT 1", [$order['id']]);
            Tracker::record($event, ['user_id' => $order['user_id'] !== null ? (int) $order['user_id'] : null, 'order_id' => (int) $order['id'],
                'amount' => (string) $order['grand_total'], 'link_id' => $placed['link_id'] ?? null, 'first_link_id' => $placed['first_link_id'] ?? null]);
        } catch (\Throwable $e) {
            Logger::error('Order status signal failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A new account: consent as chosen on the registration form, guest history, attribution and the lead record.
     * @param array<string,mixed> $post the registration form
     */
    public static function registered(int $userId, array $post, ?string $ip): void
    {
        try {
            $email = !empty($post['marketing_email']) || !empty($post['marketing_opt_in']);
            (new Consent())->setForUser($userId, ['email' => $email, 'sms' => !empty($post['marketing_sms']),
                'personalisation' => array_key_exists('personalisation_shown', $post) ? !empty($post['personalisation']) : true], 'registration', $ip);
            Tracker::claimGuestHistory($userId);
            Tracker::record('registered', ['user_id' => $userId]);
            (new LeadService())->fromUser($userId, 'registration');
        } catch (\Throwable $e) {
            Logger::error('Registration signal failed', ['error' => $e->getMessage()]);
        }
    }

    public static function signedIn(int $userId): void
    {
        Tracker::claimGuestHistory($userId);
    }
}
