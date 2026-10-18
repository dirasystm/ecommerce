<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Repositories\CatalogueRepository;

final class CatalogueRepositoryTest extends DbTestCase
{
    private CatalogueRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        CatalogueRepository::flushCache();
        $this->repo = new CatalogueRepository($this->db);
    }

    public function testAvailableStockExcludesReservedUnits(): void
    {
        $v = $this->makeVariant(10);
        $this->db->exec('UPDATE products SET is_featured = 1');
        $this->db->exec('UPDATE inventory SET reserved = 7 WHERE variant_id = ?', [$v['variant_id']]);
        $card = $this->repo->featured()[0];
        $this->assertSame(3, $card['available']);
        $this->assertTrue($card['low_stock']);
    }

    public function testCardsCarryFirstImageAndOptionFlag(): void
    {
        $a = $this->makeVariant(5);
        $this->makeVariant(5, '1200.00', $a['product_id'], 'Large');                       // a second option
        $b = $this->makeVariant(5);
        $this->db->insert('product_images', ['product_id' => $a['product_id'], 'path' => 'products/second.jpg', 'sort_order' => 2]);
        $this->db->insert('product_images', ['product_id' => $a['product_id'], 'path' => 'products/first.jpg', 'sort_order' => 1]);
        $this->db->exec('UPDATE products SET is_featured = 1');

        $cards = array_column($this->repo->featured(), null, 'id');
        $this->assertSame('products/first.jpg', $cards[$a['product_id']]['image']);
        $this->assertTrue($cards[$a['product_id']]['has_options']);
        $this->assertNull($cards[$b['product_id']]['image']);
        $this->assertFalse($cards[$b['product_id']]['has_options']);
    }

    public function testTopDealsRankByDiscountAndSkipHiddenProducts(): void
    {
        $small = $this->makeVariant(5, '900.00');
        $big = $this->makeVariant(5, '500.00');
        $hidden = $this->makeVariant(5, '100.00');
        $none = $this->makeVariant(5, '700.00');
        $this->db->exec('UPDATE product_variants SET compare_at_price = 1000 WHERE id IN (?, ?, ?)', [$small['variant_id'], $big['variant_id'], $hidden['variant_id']]);
        $this->db->exec('UPDATE products SET is_active = 0 WHERE id = ?', [$hidden['product_id']]);

        $ids = array_column($this->repo->topDeals(), 'id');
        $this->assertSame([$big['product_id'], $small['product_id']], $ids, '50% before 10%; hidden and full-price products left out');
        $this->assertNotContains($none['product_id'], $ids);
    }

    public function testFlashPriceOnlyAppliesInsideSaleWindow(): void
    {
        $v = $this->makeVariant(5, '1000.00');
        $this->db->exec('UPDATE products SET is_featured = 1');
        $sale = $this->db->insert('flash_sales', [
            'title' => 'Ended', 'starts_at' => date('Y-m-d H:i:s', time() - 7200), 'ends_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $this->db->insert('flash_sale_items', ['flash_sale_id' => $sale, 'variant_id' => $v['variant_id'], 'sale_price' => '800.00']);
        $this->assertSame('1000.00', $this->repo->featured()[0]['price'], 'ended sale must not apply');
        $this->assertNull($this->repo->activeFlashSale());

        $this->db->exec('UPDATE flash_sales SET ends_at = NOW() + INTERVAL 1 HOUR WHERE id = ?', [$sale]);
        $this->assertSame('800.00', $this->repo->featured()[0]['price']);
        $this->assertCount(1, $this->repo->activeFlashSale()['products']);

        $this->db->exec('UPDATE flash_sales SET is_active = 0 WHERE id = ?', [$sale]);
        $this->assertSame('1000.00', $this->repo->featured()[0]['price'], 'deactivated sale must not apply');
    }

    public function testInactiveProductsVariantsAndCategoriesAreHidden(): void
    {
        $v = $this->makeVariant(5);
        $this->db->exec('UPDATE products SET is_featured = 1');
        $this->assertCount(1, $this->repo->featured());

        $this->db->exec('UPDATE products SET is_active = 0');
        $this->assertCount(0, $this->repo->featured());
        $this->db->exec('UPDATE products SET is_active = 1');

        $this->db->exec('UPDATE product_variants SET is_active = 0 WHERE id = ?', [$v['variant_id']]);
        $this->assertCount(0, $this->repo->featured());
        $this->db->exec('UPDATE product_variants SET is_active = 1 WHERE id = ?', [$v['variant_id']]);

        $this->db->exec('UPDATE categories SET is_active = 0');
        $this->assertCount(0, $this->repo->featured());
    }

    public function testCategoryTreeNestsActiveChildren(): void
    {
        $parent = $this->db->insert('categories', ['name' => 'Phones', 'slug' => 'phones', 'sort_order' => 1]);
        $this->db->insert('categories', ['parent_id' => $parent, 'name' => 'Smartphones', 'slug' => 'smartphones']);
        $this->db->insert('categories', ['parent_id' => $parent, 'name' => 'Hidden', 'slug' => 'hidden', 'is_active' => 0]);
        $tree = $this->repo->categoryTree();
        $this->assertSame('Phones', $tree[0]['name']);
        $this->assertSame(['Smartphones'], array_column($tree[0]['children'], 'name'));
    }

    public function testCategoryRailIncludesChildCategoryProducts(): void
    {
        $parent = $this->db->insert('categories', ['name' => 'Phones', 'slug' => 'phones']);
        $child = $this->db->insert('categories', ['parent_id' => $parent, 'name' => 'Smartphones', 'slug' => 'smartphones']);
        $v = $this->makeVariant(3);
        $this->db->exec('UPDATE products SET category_id = ? WHERE id = ?', [$child, $v['product_id']]);
        $this->assertCount(1, $this->repo->inCategoryTree($parent));
    }
}
