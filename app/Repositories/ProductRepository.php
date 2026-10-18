<?php

declare(strict_types=1);

namespace Waimart\Repositories;

use Waimart\Core\Database;
use Waimart\Services\PricingService;

/**
 * Product detail page data: product, active variants with live price and availability,
 * images, specifications, breadcrumbs and related products.
 */
final class ProductRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findActiveBySlug(string $slug): ?array
    {
        $p = $this->db->one(
            'SELECT p.*, b.name AS brand_name, b.slug AS brand_slug, c.name AS category_name, c.slug AS category_slug, c.parent_id AS category_parent_id
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
               LEFT JOIN brands b ON b.id = p.brand_id AND b.is_active = 1
              WHERE p.slug = ? AND p.is_active = 1',
            [$slug]
        );
        if ($p === null) {
            return null;
        }
        $p['variants'] = $this->variants((int) $p['id']);
        if (!$p['variants']) {
            return null;     // nothing sellable
        }
        $p['images'] = $this->db->all('SELECT id, path, alt_text FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$p['id']]);
        $specs = $p['specifications'] ? json_decode((string) $p['specifications'], true) : [];
        $p['specs'] = is_array($specs) ? $specs : [];
        $p['breadcrumbs'] = $this->breadcrumbs((int) $p['category_id']);
        return $p;
    }

    /** @return list<array<string,mixed>> */
    public function variants(int $productId): array
    {
        $rows = $this->db->all(
            'SELECT v.id, v.sku, v.name, v.price, v.compare_at_price, v.is_default, fs.sale_price, fs.sale_ends_at,
                    (i.on_hand - i.reserved) AS available, i.low_stock_threshold
               FROM product_variants v
               JOIN inventory i ON i.variant_id = v.id
               LEFT JOIN (' . PricingService::ACTIVE_FLASH_SQL . ') fs ON fs.variant_id = v.id
              WHERE v.product_id = ? AND v.is_active = 1
              ORDER BY v.is_default DESC, v.sort_order, v.id',
            [$productId]
        );
        $attrs = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            foreach ($this->db->all(
                'SELECT variant_id, attr_name, attr_value FROM variant_attributes WHERE variant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            ) as $a) {
                $attrs[(int) $a['variant_id']][$a['attr_name']] = $a['attr_value'];
            }
        }
        return array_map(function (array $r) use ($attrs) {
            $pricing = PricingService::resolve(
                (string) $r['price'],
                $r['compare_at_price'] !== null ? (string) $r['compare_at_price'] : null,
                $r['sale_price'] !== null ? (string) $r['sale_price'] : null
            );
            $available = max(0, (int) $r['available']);
            return [
                'id' => (int) $r['id'],
                'sku' => $r['sku'],
                'name' => $r['name'],
                'is_default' => (int) $r['is_default'] === 1,
                'available' => $available,
                'low_stock' => $available > 0 && $available <= (int) $r['low_stock_threshold'],
                'attributes' => $attrs[(int) $r['id']] ?? [],
                'sale_ends_at' => $r['sale_ends_at'],
            ] + $pricing;
        }, $rows);
    }

    /** @return list<array{name:string,slug:string}> root first */
    public function breadcrumbs(int $categoryId): array
    {
        $trail = [];
        for ($id = $categoryId, $guard = 0; $id && $guard < 5; $guard++) {
            $c = $this->db->one('SELECT id, parent_id, name, slug FROM categories WHERE id = ?', [$id]);
            if ($c === null) {
                break;
            }
            array_unshift($trail, ['name' => $c['name'], 'slug' => $c['slug']]);
            $id = $c['parent_id'] ? (int) $c['parent_id'] : 0;
        }
        return $trail;
    }

    /** @return list<array<string,mixed>> */
    public function related(array $product, int $limit = 12): array
    {
        return (new CatalogueRepository($this->db))->cards(
            'p.category_id = ? AND p.id <> ?',
            [(int) $product['category_id'], (int) $product['id']],
            '(i.on_hand - i.reserved > 0) DESC, p.is_featured DESC, p.id DESC',
            $limit
        );
    }

    /** @param list<int> $ids @return list<array<string,mixed>> in the given order */
    public function cardsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $cards = (new CatalogueRepository($this->db))->cards(
            'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
            'p.id',
            count($ids)
        );
        $byId = array_column($cards, null, 'id');
        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }
}
