<?php

declare(strict_types=1);

namespace Waimart\Controllers\Seller;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\InventoryService;
use Waimart\Services\SellerProductService;

/**
 * The seller's own products. Product ids in URLs are only ever looked up within the current seller
 * (SellerProductService::owned), so another seller's product is a 404.
 */
final class ProductController extends SellerController
{
    private const STATUSES = ['draft', 'pending', 'published', 'rejected', 'archived'];

    private function service(): SellerProductService
    {
        return new SellerProductService();
    }

    private function id(Request $req, string $key = 'id'): int
    {
        $v = (string) $req->param($key);
        return ctype_digit($v) ? (int) $v : 0;
    }

    public function index(Request $req): Response
    {
        $status = (string) ($req->query['status'] ?? '');
        $status = in_array($status, self::STATUSES, true) ? $status : null;
        return $this->view('products/index', [
            'title' => 'My products', 'status' => $status,
            'rows' => $this->service()->list($this->sellerId(), $status),
            'counts' => $this->service()->counts($this->sellerId()),
            'moderated' => SellerProductService::moderationRequired(),
        ]);
    }

    public function create(Request $req): Response
    {
        return $this->view('products/create', ['title' => 'Add a product'] + $this->lookups());
    }

    public function store(Request $req): Response
    {
        $id = $this->service()->create($this->sellerId(), (int) Auth::id(), $req->post);
        return $this->success('Product saved as a draft. Add photos, then submit it for approval.', "/seller/products/$id");
    }

    public function edit(Request $req): Response
    {
        $sid = $this->sellerId();
        $id = $this->id($req);
        $product = $this->service()->owned($sid, $id);
        return $this->view('products/edit', [
            'title' => $product['name'], 'product' => $product,
            'variants' => $this->service()->variants($sid, $id),
            'images' => $this->service()->images($sid, $id),
            'moderated' => SellerProductService::moderationRequired(),
            'receiptReasons' => InventoryService::RECEIPT_REASONS,
            'adjustReasons' => InventoryService::ADJUST_REASONS,
        ] + $this->lookups());
    }

    public function update(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->update($this->sellerId(), $id, (int) Auth::id(), $req->post);
        return $this->success('Product saved.', "/seller/products/$id");
    }

    public function submit(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->submit($this->sellerId(), $id, (int) Auth::id());
        return $this->success(SellerProductService::moderationRequired()
            ? 'Submitted. ' . brand_name() . ' will review the product and email you.' : 'Published — the product is now in the shop.', "/seller/products/$id");
    }

    public function archive(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->archive($this->sellerId(), $id, (int) Auth::id());
        return $this->success('Product archived and removed from the shop.', "/seller/products/$id");
    }

    public function restore(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->restore($this->sellerId(), $id, (int) Auth::id());
        return $this->success('Product restored as a draft.', "/seller/products/$id");
    }

    public function addVariant(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->addVariant($this->sellerId(), $id, (int) Auth::id(), $req->post);
        return $this->success('Option added.', "/seller/products/$id#variants");
    }

    public function updateVariant(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->updateVariant($this->sellerId(), $id, $this->id($req, 'vid'), (int) Auth::id(), $req->post);
        return $this->success('Option saved.', "/seller/products/$id#variants");
    }

    public function defaultVariant(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->setDefaultVariant($this->sellerId(), $id, $this->id($req, 'vid'), (int) Auth::id());
        return $this->success('Default option updated.', "/seller/products/$id#variants");
    }

    public function stock(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->changeStock($this->sellerId(), $id, $this->id($req, 'vid'), (int) Auth::id(), $req->post);
        return $this->success('Stock updated.', "/seller/products/$id#variants");
    }

    public function uploadImage(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->addImage($this->sellerId(), $id, (int) Auth::id(), (array) ($req->files['image'] ?? []), (string) $req->input('alt_text', ''));
        return $this->success('Photo uploaded.', "/seller/products/$id#images");
    }

    public function deleteImage(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->deleteImage($this->sellerId(), $id, $this->id($req, 'iid'), (int) Auth::id());
        return $this->success('Photo removed.', "/seller/products/$id#images");
    }

    public function mainImage(Request $req): Response
    {
        $id = $this->id($req);
        $this->service()->makeMainImage($this->sellerId(), $id, $this->id($req, 'iid'), (int) Auth::id());
        return $this->success('Main photo updated.', "/seller/products/$id#images");
    }

    /** @return array<string,mixed> active categories and brands to choose from */
    private function lookups(): array
    {
        $db = App::db();
        return [
            'categories' => $db->all(
                'SELECT c.id, c.name, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
                  WHERE c.is_active = 1 AND (p.id IS NULL OR p.is_active = 1)
                 ORDER BY COALESCE(p.sort_order, c.sort_order), COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.name'
            ),
            'brands' => $db->all('SELECT id, name FROM brands WHERE is_active = 1 ORDER BY name'),
        ];
    }
}
