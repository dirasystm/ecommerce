<?php

declare(strict_types=1);

namespace Waimart\Repositories;

use Waimart\Core\Database;
use Waimart\Services\PricingService;

/**
 * Read-side catalogue queries for the storefront.
 */
final class CatalogueRepository
{
    /**
     * THE public-visibility rule, used by every storefront query: a product is listed and purchasable only when
     * it is active, published (moderation) and its seller is active. Use with the products alias `p`.
     */
    public const SELLER_JOIN = "JOIN sellers ps ON ps.id = p.seller_id AND ps.status = 'active'";
    public const PUBLIC_PRODUCT = "p.is_active = 1 AND p.moderation_status = 'published'";
    /** Same rule as a 0/1 expression, for queries that must still return unavailable rows (e.g. the cart). Needs `LEFT JOIN sellers ps`. */
    public const PUBLIC_EXPR = "(p.is_active = 1 AND p.moderation_status = 'published' AND ps.status = 'active')";

    /** @var list<array<string,mixed>>|null */
    private static ?array $tree = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * Active top-level categories with their active children.
     * @return list<array<string,mixed>>
     */
    public function categoryTree(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }
        $rows = $this->db->all(
            'SELECT id, parent_id, name, slug, icon FROM categories WHERE is_active = 1 ORDER BY sort_order, name'
        );
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        $tree = [];
        foreach ($byParent[0] ?? [] as $top) {
            $top['children'] = $byParent[(int) $top['id']] ?? [];
            $tree[] = $top;
        }
        return self::$tree = $tree;
    }

    public static function flushCache(): void
    {
        self::$tree = null;
    }

    /** @return list<array<string,mixed>> */
    public function heroBanners(): array
    {
        return $this->db->all(
            "SELECT * FROM banners
              WHERE position = 'hero' AND is_active = 1
                AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at > NOW())
              ORDER BY sort_order, id LIMIT 6"
        );
    }

    /** @return array{sale:array<string,mixed>, products:list<array<string,mixed>>}|null */
    public function activeFlashSale(int $limit = 12): ?array
    {
        $sale = $this->db->one(
            'SELECT * FROM flash_sales WHERE is_active = 1 AND starts_at <= NOW() AND ends_at > NOW() ORDER BY ends_at LIMIT 1'
        );
        if ($sale === null) {
            return null;
        }
        $products = $this->cards(
            'EXISTS (SELECT 1 FROM flash_sale_items x WHERE x.flash_sale_id = ? AND x.variant_id = v.id)',
            [(int) $sale['id']],
            'available > 0 DESC, p.id',
            $limit
        );
        return ['sale' => $sale, 'products' => $products];
    }

    /** @return list<array<string,mixed>> */
    public function featured(int $limit = 12): array
    {
        return $this->cards('p.is_featured = 1', [], 'p.rating_avg DESC, p.id DESC', $limit);
    }

    /** @return list<array<string,mixed>> */
    public function topDeals(int $limit = 12): array
    {
        $discount = '((COALESCE(v.compare_at_price, v.price) - COALESCE(fs.sale_price, v.price)) / COALESCE(v.compare_at_price, v.price))';
        // Stage 1 ranks default variants alone (covered by ix_var_deal); stage 2 builds cards for the
        // best few, re-checking product/category visibility. Spare candidates absorb hidden products.
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT v.default_product_key AS id FROM product_variants v
               LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id
              WHERE v.default_product_key IS NOT NULL AND v.is_active = 1
                AND (v.compare_at_price IS NOT NULL OR fs.sale_price IS NOT NULL)
              ORDER BY ' . $discount . ' DESC, v.id DESC
              LIMIT ' . max(48, $limit * 4)
        ), 'id'));
        if (!$ids) {
            return [];
        }
        return $this->cards(
            'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
            $discount . ' DESC, v.id DESC',
            $limit
        );
    }

    /** Products in a category or any of its direct children. @return list<array<string,mixed>> */
    public function inCategoryTree(int $categoryId, int $limit = 12): array
    {
        return $this->cards(
            '(p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ? AND is_active = 1))',
            [$categoryId, $categoryId],
            'p.is_featured DESC, p.rating_count DESC, p.id DESC',
            $limit
        );
    }

    /**
     * Product cards: default variant, effective price (incl. flash sale) and available stock.
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function cards(string $where, array $params, string $orderBy, int $limit): array
    {
        $sql = 'SELECT ' . self::CARD_COLUMNS . '
                  FROM products p
                  JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                  ' . self::SELLER_JOIN . '
                  JOIN product_variants v ON v.default_product_key = p.id AND v.is_active = 1
                  JOIN inventory i ON i.variant_id = v.id
                  LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id
                 WHERE ' . self::PUBLIC_PRODUCT . ' AND ' . $where . '
                 ORDER BY ' . $orderBy . '
                 LIMIT ' . max(1, min(100, $limit));
        return self::hydrate($this->db, $this->db->all($sql, $params));
    }

    /** Card columns for the default variant; images and option counts are added by hydrate(). */
    public const CARD_COLUMNS = 'p.id, p.name, p.slug, p.rating_avg, p.rating_count,
        v.id AS variant_id, v.price, v.compare_at_price, fs.sale_price, fs.sale_ends_at,
        (i.on_hand - i.reserved) AS available, i.on_hand, i.low_stock_threshold';

    /**
     * Adds the first image and active option count for just the rows being shown, then builds cards.
     * (As select-list subqueries MariaDB evaluated them for every matching row before sorting.)
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function hydrate(Database $db, array $rows): array
    {
        $ids = array_values(array_unique(array_map(fn ($r) => (int) $r['id'], $rows)));
        $images = [];
        $counts = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($db->all("SELECT product_id, path FROM product_images WHERE product_id IN ($in) ORDER BY product_id, sort_order, id", $ids) as $img) {
                $images[(int) $img['product_id']] ??= $img['path'];
            }
            foreach ($db->all("SELECT product_id, COUNT(*) AS n FROM product_variants WHERE product_id IN ($in) AND is_active = 1 GROUP BY product_id", $ids) as $c) {
                $counts[(int) $c['product_id']] = (int) $c['n'];
            }
        }
        return array_map(fn ($r) => self::toCard(
            ['image' => $images[(int) $r['id']] ?? null, 'variant_count' => $counts[(int) $r['id']] ?? 1] + $r
        ), $rows);
    }

    /** @param array<string,mixed> $r */
    public static function toCard(array $r): array
    {
        $pricing = PricingService::resolve(
            (string) $r['price'],
            $r['compare_at_price'] !== null ? (string) $r['compare_at_price'] : null,
            $r['sale_price'] !== null ? (string) $r['sale_price'] : null
        );
        $available = max(0, (int) $r['available']);
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'slug' => $r['slug'],
            'image' => $r['image'],
            'rating' => (float) $r['rating_avg'],
            'rating_count' => (int) $r['rating_count'],
            'available' => $available,
            'low_stock' => $available > 0 && $available <= (int) $r['low_stock_threshold'],
            'has_options' => (int) ($r['variant_count'] ?? 1) > 1,
            'sale_ends_at' => $r['sale_ends_at'] ?? null,
        ] + $pricing;
    }
}
