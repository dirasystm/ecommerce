<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\Settings;
use Waimart\Repositories\CatalogueRepository;
use Waimart\Services\PricingService;

/**
 * Product recommendations from the shop's own data (no external AI service).
 *
 * Interest model: each recorded action counts with its weight (Marketing → Settings: viewed, chosen from search,
 * added to cart, saved, bought) and fades with age (half-life). Interest is summed per category, brand and product.
 * Candidates come from the strongest categories and brands and are ranked by interest, price fit (the price range
 * the customer usually looks at), popularity and newness. "Frequently bought together" comes from paid orders.
 *
 * Every recommendation passes the storefront's own rule (active, published, active seller, active category, default
 * variant active) AND must be in stock, so nothing unavailable is ever suggested. Dismissed products are left out,
 * and so are products the customer bought recently.
 */
final class Recommender
{
    public const PLACEMENTS = [
        'home' => ['recs_home', 'Homepage: Recommended for you'], 'similar' => ['recs_similar', 'Product page: Similar products'],
        'together' => ['recs_bought_together', 'Product page: Frequently bought together'], 'cart' => ['recs_cart', 'Cart: You may also need'],
        'account' => ['recs_account', 'My account: For you'], 'confirmation' => ['recs_confirmation', 'Order confirmation'],
        'email' => ['recs_email', 'Marketing emails'],
    ];
    private const HISTORY_DAYS = 120;

    private Database $db;
    /** @var array<string,array<string,mixed>> */
    private static array $interestCache = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    public static function enabled(string $placement): bool
    {
        return Settings::bool('recs_enabled', true) && isset(self::PLACEMENTS[$placement]) && Settings::bool(self::PLACEMENTS[$placement][0], true);
    }

    /**
     * Interest profile of a customer (or guest visitor): category, brand and product scores and a typical price range.
     * @return array{categories:array<int,float>,brands:array<int,float>,products:array<int,float>,bought:list<int>,dismissed:list<int>,price:?array{0:float,1:float},events:int}
     */
    public function interests(?int $userId, ?string $visitorId = null): array
    {
        $key = ($userId ?? 'v') . '|' . ($visitorId ?? '');
        if (isset(self::$interestCache[$key])) {
            return self::$interestCache[$key];
        }
        $empty = ['categories' => [], 'brands' => [], 'products' => [], 'bought' => [], 'dismissed' => [], 'price' => null, 'events' => 0];
        if ($userId === null && $visitorId === null) {
            return $empty;
        }
        $w = [
            'product_viewed' => Settings::int('recs_weight_view', 1), 'rec_click' => Settings::int('recs_weight_view', 1),
            'search_click' => Settings::int('recs_weight_search', 2), 'cart_added' => Settings::int('recs_weight_cart', 3),
            'wishlist_added' => Settings::int('recs_weight_wishlist', 4),
        ];
        $halfLife = max(1, Settings::int('recs_half_life_days', 14));
        [$who, $param] = $userId !== null ? ['e.user_id = ?', $userId] : ['e.visitor_id = ?', $visitorId];
        $rows = $this->db->all(
            "SELECT e.event, e.product_id, p.category_id, p.brand_id, v.price, TIMESTAMPDIFF(HOUR, e.occurred_at, NOW()) / 24 AS age
               FROM shop_events e JOIN products p ON p.id = e.product_id
               LEFT JOIN product_variants v ON v.default_product_key = p.id
              WHERE $who AND e.product_id IS NOT NULL AND e.occurred_at > NOW() - INTERVAL " . self::HISTORY_DAYS . " DAY
                AND e.event IN ('product_viewed','rec_click','search_click','cart_added','wishlist_added','rec_dismissed')
              ORDER BY e.id DESC LIMIT 600", [$param]);
        $p = $empty;
        $prices = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            if ($r['event'] === 'rec_dismissed') {
                if ((float) $r['age'] < 90) {
                    $p['dismissed'][] = $pid;
                }
                continue;
            }
            $score = ($w[$r['event']] ?? 0) * (0.5 ** ((float) $r['age'] / $halfLife));
            $this->add($p, $pid, (int) $r['category_id'], $r['brand_id'] !== null ? (int) $r['brand_id'] : null, $score);
            if ($r['price'] !== null) {
                $prices[] = (float) $r['price'];
            }
            $p['events']++;
        }
        if ($userId !== null) {
            $bought = $this->db->all(
                "SELECT oi.product_id, p.category_id, p.brand_id, oi.unit_price, TIMESTAMPDIFF(HOUR, o.placed_at, NOW()) / 24 AS age
                   FROM orders o JOIN order_items oi ON oi.order_id = o.id JOIN products p ON p.id = oi.product_id
                  WHERE o.user_id = ? AND o.status NOT IN ('cancelled','expired') AND o.placed_at > NOW() - INTERVAL 365 DAY
                  ORDER BY o.id DESC LIMIT 200", [$userId]);
            $wp = Settings::int('recs_weight_purchase', 5);
            foreach ($bought as $r) {
                $this->add($p, (int) $r['product_id'], (int) $r['category_id'], $r['brand_id'] !== null ? (int) $r['brand_id'] : null, $wp * (0.5 ** ((float) $r['age'] / ($halfLife * 4))));
                if ((float) $r['age'] < 30) {
                    $p['bought'][] = (int) $r['product_id'];
                }
                $prices[] = (float) $r['unit_price'];
                $p['events']++;
            }
        }
        if (count($prices) >= 2) {
            sort($prices);
            $q = fn (float $f) => $prices[(int) floor($f * (count($prices) - 1))];
            $p['price'] = [$q(0.15) * 0.6, $q(0.85) * 1.6];
        }
        arsort($p['categories']);
        arsort($p['brands']);
        arsort($p['products']);
        $p['bought'] = array_values(array_unique($p['bought']));
        $p['dismissed'] = array_values(array_unique($p['dismissed']));
        return self::$interestCache[$key] = $p;
    }

    /**
     * "Recommended for you": personal when there is enough activity, otherwise popular products.
     * @param list<int> $exclude
     * @return array{cards:list<array<string,mixed>>,personal:bool}
     */
    public function forYou(?int $userId, ?string $visitorId, int $limit = 12, array $exclude = []): array
    {
        $i = $this->interests($userId, $visitorId);
        $exclude = array_merge($exclude, $i['bought'], $i['dismissed']);
        if ($i['events'] < 2 || !$i['categories']) {
            return ['cards' => $this->popular($limit, $exclude), 'personal' => false];
        }
        $cats = array_slice(array_keys($i['categories']), 0, 4);
        $brands = array_slice(array_keys($i['brands']), 0, 3);
        $where = '(p.category_id IN (' . $this->in($cats) . ')' . ($brands ? ' OR p.brand_id IN (' . $this->in($brands) . ')' : '') . ')';
        $cands = $this->candidates($where, array_merge($cats, $brands), $exclude, 80);
        $maxCat = max($i['categories']) ?: 1;
        $maxBrand = $i['brands'] ? (max($i['brands']) ?: 1) : 1;
        foreach ($cands as &$c) {
            $s = 3.0 * (($i['categories'][(int) $c['category_id']] ?? 0) / $maxCat)
               + 1.5 * (($c['brand_id'] !== null ? ($i['brands'][(int) $c['brand_id']] ?? 0) : 0) / $maxBrand)
               + 0.6 * log(1 + (int) $c['sold']) + 0.3 * (float) $c['rating_avg'] / 5
               + ((int) $c['is_featured'] ? 0.2 : 0) + ((int) $c['days_old'] < 30 ? 0.3 : 0);
            if ($i['price'] !== null) {
                $s += ((float) $c['price'] >= $i['price'][0] && (float) $c['price'] <= $i['price'][1]) ? 0.8 : -0.4;
            }
            // Something already looked at a lot is still useful, but variety matters more on a feed.
            $s -= 0.4 * min(3, ($i['products'][(int) $c['id']] ?? 0));
            $c['score'] = $s;
        }
        unset($c);
        usort($cands, fn ($a, $b) => $b['score'] <=> $a['score']);
        $ids = $this->diverse($cands, $limit);
        $cards = $this->cardsInOrder($ids);
        if (count($cards) < $limit) {
            $cards = array_merge($cards, $this->popular($limit - count($cards), array_merge($exclude, $ids)));
        }
        return ['cards' => $cards, 'personal' => true];
    }

    /** Similar products: same category, a similar price, same brand preferred. @param array<string,mixed> $product */
    public function similar(array $product, int $limit = 12): array
    {
        $price = (float) ($product['variants'][0]['price'] ?? $product['price'] ?? 0);
        $cands = $this->candidates('p.category_id = ?', [(int) $product['category_id']], [(int) $product['id']], 60);
        foreach ($cands as &$c) {
            $ratio = $price > 0 ? (float) $c['price'] / $price : 1;
            $c['score'] = ($ratio >= 0.6 && $ratio <= 1.6 ? 2 : 0) - abs(log(max(0.01, $ratio)))
                + ($product['brand_id'] !== null && (int) $c['brand_id'] === (int) $product['brand_id'] ? 1 : 0)
                + 0.5 * log(1 + (int) $c['sold']) + 0.3 * (float) $c['rating_avg'] / 5;
        }
        unset($c);
        usort($cands, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $this->cardsInOrder(array_slice(array_map(fn ($c) => (int) $c['id'], $cands), 0, $limit));
    }

    /** Bought in the same paid orders as this product (nightly table). */
    public function boughtTogether(int $productId, int $limit = 6): array
    {
        $ids = array_map('intval', array_column($this->db->all('SELECT paired_id FROM product_pairs WHERE product_id = ? AND orders >= 2 ORDER BY orders DESC LIMIT 20', [$productId]), 'paired_id'));
        return $ids ? $this->cardsInOrder(array_column($this->candidates('p.id IN (' . $this->in($ids) . ')', $ids, [$productId], 20, 'byIds'), 'id'), $limit) : [];
    }

    /** Complements for a cart or an order: bought together with these items, else popular in their categories. @param list<int> $productIds */
    public function complements(array $productIds, int $limit = 6): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }
        $pairs = array_map('intval', array_column($this->db->all(
            'SELECT paired_id, SUM(orders) AS n FROM product_pairs WHERE product_id IN (' . $this->in($productIds) . ') GROUP BY paired_id ORDER BY n DESC LIMIT 30', $productIds), 'paired_id'));
        $ids = $pairs ? array_column($this->candidates('p.id IN (' . $this->in($pairs) . ')', $pairs, $productIds, 30, 'byIds'), 'id') : [];
        if (count($ids) < $limit) {
            $cats = array_map('intval', array_column($this->db->all('SELECT DISTINCT category_id FROM products WHERE id IN (' . $this->in($productIds) . ')', $productIds), 'category_id'));
            $more = $this->candidates('p.category_id IN (' . $this->in($cats) . ')', $cats, array_merge($productIds, $ids), 30);
            usort($more, fn ($a, $b) => (int) $b['sold'] <=> (int) $a['sold']);
            $ids = array_merge($ids, array_column($more, 'id'));
        }
        return $this->cardsInOrder(array_slice(array_map('intval', $ids), 0, $limit));
    }

    /** Best sellers of the last 60 days, then featured and well rated. @param list<int> $exclude */
    public function popular(int $limit = 12, array $exclude = []): array
    {
        $cands = $this->candidates('1=1', [], $exclude, max(40, $limit * 3));
        usort($cands, fn ($a, $b) => [(int) $b['sold'], (int) $b['is_featured'], (float) $b['rating_avg']] <=> [(int) $a['sold'], (int) $a['is_featured'], (float) $a['rating_avg']]);
        return $this->cardsInOrder(array_slice(array_map(fn ($c) => (int) $c['id'], $cands), 0, $limit));
    }

    /** Counts a placement being shown (one count per page view, not per product). */
    public static function shown(string $placement, int $cards): void
    {
        if ($cards <= 0) {
            return;
        }
        try {
            App::db()->exec('INSERT INTO rec_impressions (day, placement, shown) VALUES (CURDATE(), ?, 1) ON DUPLICATE KEY UPDATE shown = shown + 1', [$placement]);
        } catch (\Throwable $e) {
            Logger::error('Recommendation impression failed', ['error' => $e->getMessage()]);
        }
    }

    /** Nightly: product pairs from paid orders of the last 180 days. @return int pairs stored */
    public function rebuildPairs(): int
    {
        return $this->db->transaction(function () {
            $this->db->exec('DELETE FROM product_pairs');
            return $this->db->exec(
                "INSERT INTO product_pairs (product_id, paired_id, orders)
                 SELECT a.product_id, b.product_id, COUNT(DISTINCT a.order_id)
                   FROM order_items a JOIN order_items b ON b.order_id = a.order_id AND b.product_id <> a.product_id
                   JOIN orders o ON o.id = a.order_id
                  WHERE (o.payment_status IN ('verified','cod_collected')
                         OR o.status IN ('confirmed','processing','packed','dispatched','out_for_delivery','delivered','ready_for_collection','collected'))
                    AND o.placed_at > NOW() - INTERVAL 180 DAY
                  GROUP BY a.product_id, b.product_id");
        });
    }

    // ------------------------------------------------------------------ internals

    /** Sellable, in-stock candidates with what the ranking needs. @param list<mixed> $params @param list<int> $exclude */
    private function candidates(string $where, array $params, array $exclude, int $limit, string $order = 'default'): array
    {
        $exclude = array_values(array_unique(array_map('intval', $exclude)));
        $sql = 'SELECT p.id, p.category_id, p.brand_id, p.rating_avg, p.is_featured, DATEDIFF(NOW(), p.created_at) AS days_old,
                       COALESCE(fs.sale_price, v.price) AS price,
                       (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                         WHERE oi.product_id = p.id AND o.status NOT IN (\'cancelled\',\'expired\') AND o.placed_at > NOW() - INTERVAL 60 DAY) AS sold
                  FROM products p
                  JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                  ' . CatalogueRepository::SELLER_JOIN . '
                  JOIN product_variants v ON v.default_product_key = p.id AND v.is_active = 1
                  JOIN inventory i ON i.variant_id = v.id
                  LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id
                 WHERE ' . CatalogueRepository::PUBLIC_PRODUCT . ' AND (i.on_hand - i.reserved) > 0 AND ' . $where
                 . ($exclude ? ' AND p.id NOT IN (' . $this->in($exclude) . ')' : '')
                 . ($order === 'byIds' ? '' : ' ORDER BY p.is_featured DESC, p.rating_count DESC, p.id DESC') . ' LIMIT ' . max(1, min(200, $limit));
        $rows = $this->db->all($sql, array_merge($params, $exclude));
        if ($order === 'byIds') {
            $pos = array_flip(array_map('intval', $params));
            usort($rows, fn ($a, $b) => ($pos[(int) $a['id']] ?? 999) <=> ($pos[(int) $b['id']] ?? 999));
        }
        return $rows;
    }

    /** Takes the best, at most three from one category in a row, so a feed is not one category only. */
    private function diverse(array $ranked, int $limit): array
    {
        $out = [];
        $perCat = [];
        foreach ($ranked as $c) {
            $cat = (int) $c['category_id'];
            if (($perCat[$cat] ?? 0) >= max(3, (int) ceil($limit / 3))) {
                continue;
            }
            $perCat[$cat] = ($perCat[$cat] ?? 0) + 1;
            $out[] = (int) $c['id'];
            if (count($out) >= $limit) {
                break;
            }
        }
        foreach ($ranked as $c) {
            if (count($out) >= $limit) {
                break;
            }
            if (!in_array((int) $c['id'], $out, true)) {
                $out[] = (int) $c['id'];
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return list<array<string,mixed>> product cards in the given order */
    private function cardsInOrder(array $ids, ?int $limit = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($limit !== null) {
            $ids = array_slice($ids, 0, $limit);
        }
        if (!$ids) {
            return [];
        }
        $cards = (new CatalogueRepository($this->db))->cards('p.id IN (' . $this->in($ids) . ') AND (i.on_hand - i.reserved) > 0', $ids, 'p.id', count($ids));
        $byId = array_column($cards, null, 'id');
        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }

    private function add(array &$p, int $pid, int $cat, ?int $brand, float $score): void
    {
        $p['products'][$pid] = ($p['products'][$pid] ?? 0) + $score;
        $p['categories'][$cat] = ($p['categories'][$cat] ?? 0) + $score;
        if ($brand !== null) {
            $p['brands'][$brand] = ($p['brands'][$brand] ?? 0) + $score;
        }
    }

    /** @param list<int> $ids */
    private function in(array $ids): string
    {
        return implode(',', array_fill(0, max(1, count($ids)), '?'));
    }

    /** Tests only. */
    public static function flush(): void
    {
        self::$interestCache = [];
    }
}
