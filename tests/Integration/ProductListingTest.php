<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Repositories\ProductListing;
use Waimart\Repositories\ProductRepository;

final class ProductListingTest extends DbTestCase
{
    private ProductListing $listing;
    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listing = new ProductListing($this->db);
        $this->cat = $this->db->insert('categories', ['name' => 'Electronics', 'slug' => 'electronics']);
        $brandA = $this->db->insert('brands', ['name' => 'Alpha', 'slug' => 'alpha']);
        $brandB = $this->db->insert('brands', ['name' => 'Beta', 'slug' => 'beta']);
        foreach ([
            ['Alpha 43 inch TV', $brandA, '30000', '35000', 5],
            ['Alpha Soundbar', $brandA, '8000', null, 0],
            ['Beta 55 inch TV', $brandB, '50000', null, 2],
            ['Beta Radio', $brandB, '2000', '2500', 9],
        ] as [$name, $brand, $price, $was, $stock]) {
            $pid = $this->db->insert('products', ['seller_id' => \Waimart\Services\Sellers::platformId($this->db), 'category_id' => $this->cat, 'brand_id' => $brand, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))]);
            $vid = $this->db->insert('product_variants', ['product_id' => $pid, 'sku' => strtoupper(substr(md5($name), 0, 8)), 'price' => $price, 'compare_at_price' => $was, 'is_default' => 1]);
            $this->db->insert('inventory', ['variant_id' => $vid, 'on_hand' => $stock]);
        }
    }

    private function names(array $result): array
    {
        return array_column($result['items'], 'name');
    }

    public function testCategoryListingPutsInStockFirstAndCountsBrands(): void
    {
        $r = $this->listing->find(['category_ids' => [$this->cat]]);
        $this->assertSame(4, $r['total']);
        $this->assertSame('Alpha Soundbar', end($r['items'])['name'], 'out of stock goes last');
        $this->assertSame([['slug' => 'alpha', 'name' => 'Alpha', 'n' => 2], ['slug' => 'beta', 'name' => 'Beta', 'n' => 2]],
            array_map(fn ($b) => ['slug' => $b['slug'], 'name' => $b['name'], 'n' => (int) $b['n']], $r['brands']));
    }

    public function testFiltersCombine(): void
    {
        $r = $this->listing->find(['category_ids' => [$this->cat], 'brands' => ['alpha'], 'in_stock' => true]);
        $this->assertSame(['Alpha 43 inch TV'], $this->names($r));

        $r = $this->listing->find(['category_ids' => [$this->cat], 'min' => '5000', 'max' => '40000']);
        $this->assertEqualsCanonicalizing(['Alpha 43 inch TV', 'Alpha Soundbar'], $this->names($r));

        $r = $this->listing->find(['category_ids' => [$this->cat], 'on_sale' => true]);
        $this->assertEqualsCanonicalizing(['Alpha 43 inch TV', 'Beta Radio'], $this->names($r));
    }

    public function testBrandFacetIgnoresOwnFilter(): void
    {
        $r = $this->listing->find(['category_ids' => [$this->cat], 'brands' => ['alpha']]);
        $this->assertCount(2, $r['brands'], 'other brands remain selectable');
    }

    public function testSortingByPriceAndDiscount(): void
    {
        $r = $this->listing->find(['category_ids' => [$this->cat], 'sort' => 'price_asc', 'in_stock' => true]);
        $this->assertSame(['Beta Radio', 'Alpha 43 inch TV', 'Beta 55 inch TV'], $this->names($r));
        $r = $this->listing->find(['category_ids' => [$this->cat], 'sort' => 'discount', 'in_stock' => true]);
        $this->assertSame('Beta Radio', $r['items'][0]['name'], '20% beats 14%');
    }

    public function testSearchMatchesShortWordsBrandsAndIsInjectionSafe(): void
    {
        $this->assertCount(2, $this->listing->find(['q' => 'tv'])['items'], 'two-letter word via LIKE fallback');
        $this->assertCount(2, $this->listing->find(['q' => 'beta'])['items'], 'brand name');
        $catName = (string) $this->db->value('SELECT name FROM categories WHERE id = ?', [$this->cat]);
        $this->assertSame(4, $this->listing->find(['q' => $catName])['total'], 'category name');
        $this->assertSame([], $this->listing->find(['q' => 'zzzznomatch'])['brands'], 'no matches, no facet');
        $this->assertCount(1, $this->listing->find(['q' => 'sound'])['items'], 'prefix search');
        $this->assertSame(0, $this->listing->find(['q' => "' OR 1=1 -- %_"])['total']);
        $this->assertSame(0, $this->listing->find(['q' => '+-~*()"@<>'])['total']);
        $this->assertSame('relevance', $this->listing->find(['q' => 'alpha'])['sort']);
    }

    public function testPaginationClampsOutOfRangePages(): void
    {
        $r = $this->listing->find(['category_ids' => [$this->cat]], 99, 3);
        $this->assertSame(2, $r['page']);
        $this->assertSame(2, $r['pages']);
        $this->assertCount(1, $r['items']);
    }

    public function testProductPageDataHidesInactiveVariantsAndProducts(): void
    {
        $repo = new ProductRepository($this->db);
        $p = $repo->findActiveBySlug('beta-radio');
        $this->assertNotNull($p);
        $this->assertSame(9, $p['variants'][0]['available']);
        $this->assertSame(20, $p['variants'][0]['discount_percent']);

        $this->db->exec("UPDATE products SET is_active = 0 WHERE slug = 'beta-radio'");
        $this->assertNull($repo->findActiveBySlug('beta-radio'));
        $this->assertNull($repo->findActiveBySlug('missing'));
    }
}
