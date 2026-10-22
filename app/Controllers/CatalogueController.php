<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Repositories\CatalogueRepository;
use Waimart\Repositories\ProductListing;

/**
 * Category, brand, search and flash-sale listing pages (all GET, shareable URLs).
 */
final class CatalogueController extends Controller
{
    public function category(Request $req): Response
    {
        $db = App::db();
        $cat = $db->one('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [(string) $req->param('slug')]);
        if ($cat === null) {
            throw HttpException::notFound('Category not found.');
        }
        $children = $db->all('SELECT id, name, slug FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, name', [$cat['id']]);
        $parent = $cat['parent_id'] ? $db->one('SELECT name, slug FROM categories WHERE id = ?', [$cat['parent_id']]) : null;
        $siblings = $parent ? $db->all('SELECT name, slug FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, name', [$cat['parent_id']]) : [];

        $criteria = $this->criteria($req) + ['category_ids' => [(int) $cat['id'], ...array_map('intval', array_column($children, 'id'))]];
        \Waimart\Services\Marketing\Tracker::record('category_viewed', ['category_id' => (int) $cat['id']]);
        $result = (new ProductListing($db))->find($criteria, $this->page($req));

        $crumbs = [];
        if ($parent) {
            $crumbs[] = ['name' => $parent['name'], 'url' => url('/category/' . $parent['slug'])];
        }
        $crumbs[] = ['name' => $cat['name'], 'url' => null];

        return $this->view('catalogue/listing', [
            'title' => $cat['name'],
            'metaDescription' => 'Shop ' . $cat['name'] . ' at ' . brand_name() . '. Pay with M-Pesa or cash on delivery.',
            'heading' => $cat['name'],
            'crumbs' => $crumbs,
            'result' => $result,
            'criteria' => $criteria,
            'subcategories' => $children ?: $siblings,
            'currentSlug' => $cat['slug'],
            'path' => '/category/' . $cat['slug'],
        ]);
    }

    public function brand(Request $req): Response
    {
        $brand = App::db()->one('SELECT * FROM brands WHERE slug = ? AND is_active = 1', [(string) $req->param('slug')]);
        if ($brand === null) {
            throw HttpException::notFound('Brand not found.');
        }
        $criteria = $this->criteria($req) + ['brand_id' => (int) $brand['id']];
        \Waimart\Services\Marketing\Tracker::record('brand_viewed', ['brand_id' => (int) $brand['id']]);
        unset($criteria['brands']);
        return $this->view('catalogue/listing', [
            'title' => $brand['name'],
            'heading' => $brand['name'],
            'crumbs' => [['name' => 'Brands', 'url' => null], ['name' => $brand['name'], 'url' => null]],
            'result' => (new ProductListing(App::db()))->find($criteria, $this->page($req)),
            'criteria' => $criteria,
            'hideBrandFacet' => true,
            'path' => '/brand/' . $brand['slug'],
        ]);
    }

    public function search(Request $req): Response
    {
        $q = mb_substr(trim((string) $req->input('q', '')), 0, 100);
        $criteria = $this->criteria($req) + ['q' => $q];
        if ($q !== '') {
            \Waimart\Services\Marketing\Tracker::record('searched', ['query' => $q]);
        }
        $result = (new ProductListing(App::db()))->find($criteria, $this->page($req));
        return $this->view('catalogue/listing', [
            'title' => $q !== '' ? 'Search: ' . $q : 'All products',
            'heading' => $q !== '' ? 'Results for “' . $q . '”' : 'All products',
            'crumbs' => [['name' => $q !== '' ? 'Search results' : 'All products', 'url' => null]],
            'result' => $result,
            'criteria' => $criteria,
            'allowRelevance' => $q !== '',
            'path' => '/search',
            'searchQuery' => $q,
        ]);
    }

    /** A seller's public store: profile + that seller's visible products, with in-store category and search filters. */
    public function store(Request $req): Response
    {
        $stores = new \Waimart\Repositories\StoreRepository(App::db());
        $store = $stores->findPublic((string) $req->param('slug'));
        if ($store === null) {
            // A store whose address changed (e.g. the Official Store after the rebrand) keeps its old links working.
            $current = App::db()->value('SELECT s.slug FROM seller_slug_aliases a JOIN sellers s ON s.id = a.seller_id WHERE a.slug = ?', [(string) $req->param('slug')]);
            if ($current) {
                return Response::redirect('/store/' . $current, 301);
            }
            throw HttpException::notFound('This store is not available.');
        }
        $categories = $stores->categories((int) $store['id']);
        \Waimart\Services\Marketing\Tracker::record('store_viewed', ['seller_id' => (int) $store['id']]);
        $criteria = $this->criteria($req) + ['seller_id' => (int) $store['id']];
        $catParam = (string) ($req->query['category'] ?? '');
        $current = null;
        foreach ($categories as $c) {
            if ($catParam === (string) $c['id']) {
                $current = $c;
                $criteria['category_ids'] = [$c['id']];
            }
        }
        $q = mb_substr(trim((string) ($req->query['q'] ?? '')), 0, 100);
        if ($q !== '') {
            $criteria['q'] = $q;
        }
        $path = '/store/' . $store['slug'];
        $categoryLinks = array_merge(
            [['name' => 'All products', 'url' => url($path), 'active' => $current === null]],
            array_map(fn ($c) => ['name' => $c['name'] . ' (' . $c['n'] . ')', 'url' => url($path, ['category' => $c['id']]), 'active' => $current === $c], $categories)
        );
        return $this->view('catalogue/listing', [
            'title' => $store['name'],
            'metaDescription' => 'Shop ' . $store['name'] . ' on ' . brand_name() . '. ' . mb_substr((string) $store['description'], 0, 120),
            'heading' => $current ? $store['name'] . ': ' . $current['name'] : ($q !== '' ? 'Results for “' . $q . '”' : 'Products from ' . $store['name']),
            'crumbs' => [['name' => 'Stores', 'url' => null], ['name' => $store['name'], 'url' => null]],
            'result' => (new ProductListing(App::db()))->find($criteria, $this->page($req)),
            'criteria' => $criteria,
            'store' => $store,
            'categoryLinks' => $categoryLinks,
            'allowRelevance' => $q !== '',
            'searchQuery' => $q !== '' ? $q : null,
            'hiddenParams' => $current ? ['category' => (string) $current['id']] : [],
            'emptyMessage' => 'This store has no products matching your filters right now.',
            'path' => $path,
        ]);
    }

    public function flashSales(Request $req): Response
    {
        $flash = (new CatalogueRepository(App::db()))->activeFlashSale(1);
        $criteria = $this->criteria($req);
        $result = $flash
            ? (new ProductListing(App::db()))->find($criteria + ['flash_sale_id' => (int) $flash['sale']['id']], $this->page($req))
            : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 24, 'brands' => [], 'sort' => 'popular'];
        return $this->view('catalogue/listing', [
            'title' => 'Flash Sales',
            'heading' => $flash ? $flash['sale']['title'] : 'Flash Sales',
            'crumbs' => [['name' => 'Flash Sales', 'url' => null]],
            'result' => $result,
            'criteria' => $criteria,
            'flashEndsAt' => $flash['sale']['ends_at'] ?? null,
            'emptyMessage' => 'There is no flash sale running right now. Check back soon!',
            'path' => '/flash-sales',
        ]);
    }

    /** Whitelisted, validated filter input. @return array<string,mixed> */
    private function criteria(Request $req): array
    {
        $brands = $req->query['brand'] ?? [];
        $brands = is_array($brands) ? array_values(array_filter($brands, fn ($b) => is_string($b) && preg_match('/^[a-z0-9-]{1,140}$/', $b))) : [];
        $money = fn ($v) => is_string($v) && preg_match('/^\d{1,9}(\.\d{1,2})?$/', trim($v)) ? trim($v) : null;
        $sort = (string) ($req->query['sort'] ?? '');
        return [
            'brands' => $brands,
            'min' => $money($req->query['min'] ?? null),
            'max' => $money($req->query['max'] ?? null),
            'in_stock' => ($req->query['in_stock'] ?? '') === '1',
            'on_sale' => ($req->query['on_sale'] ?? '') === '1',
            'sort' => isset(ProductListing::SORTS[$sort]) || $sort === 'relevance' ? $sort : '',
        ];
    }

    private function page(Request $req): int
    {
        $p = (string) ($req->query['page'] ?? '1');
        return preg_match('/^\d{1,4}$/', $p) ? max(1, (int) $p) : 1;
    }
}
