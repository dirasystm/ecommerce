<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\CatalogueAdminService;

final class ProductController extends AdminController
{
    private const PER_PAGE = 25;

    public function index(Request $req): Response
    {
        $db = App::db();
        $where = ['1 = 1'];
        $params = [];
        $q = trim((string) ($req->query['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $where[] = '(p.name LIKE ? OR EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id AND x.sku LIKE ?))';
            array_push($params, $like, $like);
        }
        $cat = (string) ($req->query['category'] ?? '');
        if (ctype_digit($cat)) {
            $where[] = '(p.category_id = ? OR c.parent_id = ?)';
            array_push($params, (int) $cat, (int) $cat);
        }
        $status = (string) ($req->query['status'] ?? '');
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 'p.is_active = ' . ($status === 'active' ? 1 : 0);
        }
        $seller = (string) ($req->query['seller'] ?? '');
        if (ctype_digit($seller)) {
            $where[] = 'p.seller_id = ?';
            $params[] = (int) $seller;
        }
        $moderation = (string) ($req->query['moderation'] ?? '');
        if (in_array($moderation, ['draft', 'pending', 'published', 'rejected', 'archived'], true)) {
            $where[] = 'p.moderation_status = ?';
            $params[] = $moderation;
        }
        $w = implode(' AND ', $where);
        $total = (int) $db->value("SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id WHERE $w", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($req->query['page'] ?? 1)));
        $rows = $db->all(
            "SELECT p.id, p.name, p.slug, p.is_active, p.is_featured, p.moderation_status, c.name AS category, b.name AS brand,
                    s.name AS seller_name, s.is_platform, s.status AS seller_status,
                    (SELECT path FROM product_images pi WHERE pi.product_id = p.id ORDER BY sort_order, id LIMIT 1) AS image,
                    (SELECT MIN(v.price) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS min_price,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variants,
                    (SELECT COALESCE(SUM(i.on_hand - i.reserved), 0) FROM product_variants v JOIN inventory i ON i.variant_id = v.id
                      WHERE v.product_id = p.id AND v.is_active = 1) AS available
               FROM products p
               JOIN categories c ON c.id = p.category_id
               LEFT JOIN brands b ON b.id = p.brand_id
               JOIN sellers s ON s.id = p.seller_id
              WHERE $w ORDER BY p.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        return $this->view('products/index', [
            'title' => 'Products', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'categories' => $db->all('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY sort_order, name'),
            'sellers' => $db->all('SELECT id, name FROM sellers ORDER BY is_platform DESC, name'),
        ]);
    }

    /** Seller products waiting for approval (and recent decisions). */
    public function moderation(Request $req): Response
    {
        $status = (string) ($req->query['status'] ?? 'pending');
        $status = in_array($status, ['pending', 'rejected', 'published'], true) ? $status : 'pending';
        return $this->view('products/moderation', [
            'title' => 'Product approvals', 'status' => $status,
            'rows' => (new \Waimart\Services\SellerProductService())->moderationQueue($status),
        ]);
    }

    public function approve(Request $req): Response
    {
        (new \Waimart\Services\SellerProductService())->approve($this->intParam($req, 'id'), $this->actor(), trim((string) $req->input('note', '')) ?: null);
        return $this->success('Product approved and published.', '/admin/moderation/products');
    }

    public function reject(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new \Waimart\Services\SellerProductService())->reject($id, $this->actor(), (string) $req->input('reason', ''));
        return $this->success('Product rejected. The seller has been told why.', (string) $req->input('back', '') === 'edit' ? "/admin/products/$id" : '/admin/moderation/products');
    }

    public function create(Request $req): Response
    {
        return $this->view('products/create', ['title' => 'New product'] + $this->lookups());
    }

    public function store(Request $req): Response
    {
        $id = (new CatalogueAdminService())->createProduct($req->post, $this->actor());
        return $this->success('Product created. Add images and more variants below.', "/admin/products/$id");
    }

    public function edit(Request $req): Response
    {
        $db = App::db();
        $id = $this->intParam($req, 'id');
        $product = $db->one('SELECT p.*, s.name AS seller_name, s.is_platform AS seller_is_platform, s.status AS seller_status FROM products p JOIN sellers s ON s.id = p.seller_id WHERE p.id = ?', [$id]) ?? throw HttpException::notFound();
        $variants = $db->all(
            'SELECT v.*, i.on_hand, i.reserved, i.low_stock_threshold,
                    (SELECT GROUP_CONCAT(CONCAT(attr_name, ": ", attr_value) ORDER BY attr_name SEPARATOR "\n")
                       FROM variant_attributes a WHERE a.variant_id = v.id) AS attributes_text
               FROM product_variants v JOIN inventory i ON i.variant_id = v.id
              WHERE v.product_id = ? ORDER BY v.is_default DESC, v.sort_order, v.id',
            [$id]
        );
        $images = $db->all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$id]);
        return $this->view('products/edit', ['title' => 'Edit product', 'product' => $product, 'variants' => $variants, 'images' => $images] + $this->lookups());
    }

    public function update(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new CatalogueAdminService())->updateProduct($id, $req->post, $this->actor());
        return $this->success('Product saved.', "/admin/products/$id");
    }

    public function addVariant(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new CatalogueAdminService())->addVariant($id, $req->post, $this->actor());
        return $this->success('Variant added.', "/admin/products/$id#variants");
    }

    public function updateVariant(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $vid = $this->ownedVariant($id, $this->intParam($req, 'vid'));
        (new CatalogueAdminService())->updateVariant($vid, $req->post, $this->actor());
        return $this->success('Variant saved.', "/admin/products/$id#variants");
    }

    public function defaultVariant(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new CatalogueAdminService())->setDefaultVariant($id, $this->intParam($req, 'vid'), $this->actor());
        return $this->success('Default variant updated.', "/admin/products/$id#variants");
    }

    public function uploadImage(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new CatalogueAdminService())->addImage($id, (array) ($req->files['image'] ?? []), (string) $req->input('alt_text', ''), $this->actor());
        return $this->success('Image uploaded.', "/admin/products/$id#images");
    }

    public function deleteImage(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new CatalogueAdminService())->deleteImage($id, $this->intParam($req, 'iid'), $this->actor());
        return $this->success('Image removed.', "/admin/products/$id#images");
    }

    public function mainImage(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new CatalogueAdminService())->makeMainImage($id, $this->intParam($req, 'iid'), $this->actor());
        return $this->success('Main image updated.', "/admin/products/$id#images");
    }

    private function ownedVariant(int $productId, int $variantId): int
    {
        if (!App::db()->value('SELECT id FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $productId])) {
            throw HttpException::notFound();
        }
        return $variantId;
    }

    /** @return array<string,mixed> */
    private function lookups(): array
    {
        $db = App::db();
        return [
            'categories' => $db->all(
                'SELECT c.id, c.name, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
                 ORDER BY COALESCE(p.sort_order, c.sort_order), COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.name'
            ),
            'brands' => $db->all('SELECT id, name FROM brands ORDER BY name'),
        ];
    }
}
