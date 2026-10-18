<?php

declare(strict_types=1);

namespace Waimart\Repositories;

use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Services\PricingService;

/**
 * Filtered, sorted, paginated product listings for category, brand, search and flash-sale pages.
 * Prices/discounts are computed with the same rule as PricingService (flash sale beats base price).
 */
final class ProductListing
{
    public const SORTS = [
        'popular'    => 'Popularity',
        'newest'     => 'Newest arrivals',
        'price_asc'  => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'discount'   => 'Biggest discount',
    ];

    private const EFFECTIVE_PRICE = 'LEAST(v.price, COALESCE(fs.sale_price, v.price))';
    private const WAS_PRICE = 'COALESCE(v.compare_at_price, v.price)';

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array{category_ids?:list<int>,seller_id?:int,brand_id?:int,brands?:list<string>,q?:string,min?:?string,max?:?string,
     *              in_stock?:bool,on_sale?:bool,flash_sale_id?:int,sort?:string} $c
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int,brands:list<array<string,mixed>>,sort:string}
     */
    public function find(array $c, int $page = 1, int $perPage = 24): array
    {
        [$where, $params, $relevance] = $this->conditions($c, true);
        $sort = $c['sort'] ?? '';
        if (!isset(self::SORTS[$sort]) && !($sort === 'relevance' && $relevance !== null)) {
            $sort = $relevance !== null ? 'relevance' : 'popular';
        }

        $from = $this->from();
        $total = (int) $this->db->value("SELECT COUNT(*) $from WHERE $where", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $order = match ($sort) {
            'relevance'  => "$relevance DESC, p.id DESC",
            'newest'     => 'p.created_at DESC, p.id DESC',
            'price_asc'  => self::EFFECTIVE_PRICE . ' ASC, p.id',
            'price_desc' => self::EFFECTIVE_PRICE . ' DESC, p.id',
            'discount'   => '(' . self::WAS_PRICE . ' - ' . self::EFFECTIVE_PRICE . ') / ' . self::WAS_PRICE . ' DESC, p.id',
            default      => 'p.is_featured DESC, p.rating_count DESC, p.id DESC',
        };
        // In-stock items first, whatever the sort.
        $sql = 'SELECT ' . CatalogueRepository::CARD_COLUMNS . " $from WHERE $where ORDER BY (i.on_hand - i.reserved > 0) DESC, $order LIMIT "
            . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage);
        $relevanceParams = $relevance !== null && $sort === 'relevance' ? [$this->booleanQuery((string) ($c['q'] ?? ''))] : [];
        $items = $total > 0 ? CatalogueRepository::hydrate($this->db, $this->db->all($sql, [...$params, ...$relevanceParams])) : [];

        // Brand facet ignores the brand filter itself so shoppers can widen the selection.
        // With no matches and no brand filter, the facet is necessarily empty too.
        $brands = [];
        if ($total > 0 || !empty($c['brands'])) {
            [$fWhere, $fParams] = $this->conditions(['brands' => []] + $c, false);
            $brands = $this->db->all(
                "SELECT b.slug, b.name, COUNT(*) AS n $from WHERE $fWhere AND b.id IS NOT NULL GROUP BY b.id ORDER BY b.name",
                $fParams
            );
        }

        return compact('items', 'total', 'page', 'pages', 'brands', 'sort') + ['per_page' => $perPage];
    }

    private function from(): string
    {
        return 'FROM products p
                JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                LEFT JOIN brands b ON b.id = p.brand_id AND b.is_active = 1
                ' . CatalogueRepository::SELLER_JOIN . '
                JOIN product_variants v ON v.default_product_key = p.id AND v.is_active = 1
                JOIN inventory i ON i.variant_id = v.id
                LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id';
    }

    /**
     * @return array{0:string,1:list<mixed>,2:?string} where-clause, params, relevance expression (search only)
     */
    private function conditions(array $c, bool $withBrandFilter): array
    {
        $w = [CatalogueRepository::PUBLIC_PRODUCT];
        $p = [];
        $relevance = null;

        if (!empty($c['category_ids'])) {
            $ids = array_values(array_map('intval', $c['category_ids']));
            $w[] = 'p.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($p, ...$ids);
        }
        if (!empty($c['seller_id'])) {
            $w[] = 'p.seller_id = ?';
            $p[] = (int) $c['seller_id'];
        }
        if (!empty($c['brand_id'])) {
            $w[] = 'p.brand_id = ?';
            $p[] = (int) $c['brand_id'];
        }
        if ($withBrandFilter && !empty($c['brands'])) {
            $slugs = array_values(array_slice($c['brands'], 0, 20));
            $w[] = 'b.slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')';
            array_push($p, ...$slugs);
        }
        if (!empty($c['flash_sale_id'])) {
            $w[] = 'EXISTS (SELECT 1 FROM flash_sale_items x WHERE x.flash_sale_id = ? AND x.variant_id = v.id) AND fs.sale_price IS NOT NULL';
            $p[] = (int) $c['flash_sale_id'];
        }
        if (isset($c['min']) && $c['min'] !== null && $c['min'] !== '') {
            $w[] = self::EFFECTIVE_PRICE . ' >= ?';
            $p[] = Money::normalize($c['min']);
        }
        if (isset($c['max']) && $c['max'] !== null && $c['max'] !== '') {
            $w[] = self::EFFECTIVE_PRICE . ' <= ?';
            $p[] = Money::normalize($c['max']);
        }
        if (!empty($c['in_stock'])) {
            $w[] = 'i.on_hand - i.reserved > 0';
        }
        if (!empty($c['on_sale'])) {
            $w[] = self::EFFECTIVE_PRICE . ' < ' . self::WAS_PRICE;
        }
        $q = trim((string) ($c['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $bool = $this->booleanQuery($q);
            // Brand/category names and the full-text match are resolved by uncorrelated subqueries (each run
            // once); OR-ing MATCH with joined columns stopped MariaDB using the full-text index at all.
            $parts = ['p.name LIKE ?', 'p.brand_id IN (SELECT id FROM brands WHERE is_active = 1 AND name LIKE ?)',
                'p.category_id IN (SELECT id FROM categories WHERE name LIKE ?)'];
            $params = [$like, $like, $like];
            if ($bool !== '') {
                array_unshift($parts, 'p.id IN (SELECT id FROM products WHERE MATCH(name, short_description) AGAINST (? IN BOOLEAN MODE))');
                array_unshift($params, $bool);
                $relevance = 'MATCH(p.name, p.short_description) AGAINST (? IN BOOLEAN MODE)';
            } else {
                $relevance = null;
            }
            $w[] = '(' . implode(' OR ', $parts) . ')';
            array_push($p, ...$params);
        }
        return [implode(' AND ', $w), $p, $relevance];
    }

    /** Turns free text into a safe BOOLEAN MODE query: +word* for each word of 3+ characters. */
    private function booleanQuery(string $q): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $terms[] = '+' . $word . '*';
            }
        }
        return implode(' ', array_slice(array_unique($terms), 0, 8));
    }
}
