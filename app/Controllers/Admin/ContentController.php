<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Repositories\CatalogueRepository;
use Waimart\Services\ContentService;

final class ContentController extends AdminController
{
    public function banners(Request $req): Response
    {
        $lp = \Waimart\Core\ListParams::from($req, ['order' => 'is_active DESC, sort_order', 'title' => 'title'], 'order', 'asc');
        [$where, $params] = $lp->q !== '' ? ['WHERE (title LIKE ? OR subtitle LIKE ? OR link_url LIKE ?)', [$lp->like(), $lp->like(), $lp->like()]] : ['', []];
        return $this->view('content/banners', ['title' => 'Banners', 'lp' => $lp, 'rows' => $lp->fetch(App::db(), 'SELECT *', "FROM banners $where", $params, 'id')]);
    }

    public function bannerForm(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $b = $id ? (App::db()->one('SELECT * FROM banners WHERE id = ?', [$id]) ?? throw HttpException::notFound()) : null;
        return $this->view('content/banner-form', ['title' => $b ? 'Edit banner' : 'New banner', 'b' => $b]);
    }

    public function saveBanner(Request $req): Response
    {
        $id = $this->intParam($req, 'id') ?: null;
        (new ContentService())->saveBanner($id, $req->post, $req->files['image'] ?? null, $this->actor());
        return $this->success('Banner saved.', '/admin/banners');
    }

    public function deleteBanner(Request $req): Response
    {
        (new ContentService())->deleteBanner($this->intParam($req, 'id'), $this->actor());
        return $this->success('Banner deleted.', '/admin/banners');
    }

    public function sales(Request $req): Response
    {
        $lp = \Waimart\Core\ListParams::from($req, ['ends' => 'f.ends_at', 'starts' => 'f.starts_at', 'title' => 'f.title'], 'ends', 'desc');
        $state = (string) ($req->query['state'] ?? '');
        $where = ['1=1'];
        $params = [];
        if ($lp->q !== '') {
            $where[] = 'f.title LIKE ?';
            $params[] = $lp->like();
        }
        $where[] = match ($state) {
            'live' => 'f.is_active = 1 AND f.starts_at <= NOW() AND f.ends_at > NOW()', 'scheduled' => 'f.is_active = 1 AND f.starts_at > NOW()',
            'ended' => 'f.ends_at <= NOW()', 'off' => 'f.is_active = 0', default => '1=1',
        };
        $rows = $lp->fetch(App::db(), 'SELECT f.*, (SELECT COUNT(*) FROM flash_sale_items i WHERE i.flash_sale_id = f.id) AS items',
            'FROM flash_sales f WHERE ' . implode(' AND ', $where), $params, 'f.id');
        return $this->view('content/sales', ['title' => 'Flash sales', 'rows' => $rows, 'lp' => $lp, 'state' => $state]);
    }

    public function saleForm(Request $req): Response
    {
        $db = App::db();
        $id = $this->intParam($req, 'id');
        $sale = $id ? ($db->one('SELECT * FROM flash_sales WHERE id = ?', [$id]) ?? throw HttpException::notFound()) : null;
        $items = $sale ? $db->all(
            'SELECT i.*, v.sku, v.price, v.name AS variant, p.name AS product, (inv.on_hand - inv.reserved) AS available
               FROM flash_sale_items i JOIN product_variants v ON v.id = i.variant_id JOIN products p ON p.id = v.product_id
               JOIN inventory inv ON inv.variant_id = v.id WHERE i.flash_sale_id = ? ORDER BY p.name',
            [$id]
        ) : [];
        return $this->view('content/sale-form', ['title' => $sale ? 'Edit flash sale' : 'New flash sale', 'sale' => $sale, 'items' => $items]);
    }

    public function saveSale(Request $req): Response
    {
        $id = (new ContentService())->saveSale($this->intParam($req, 'id') ?: null, $req->post, $this->actor());
        CatalogueRepository::flushCache();
        return $this->success('Flash sale saved.', "/admin/flash-sales/$id");
    }

    public function addItem(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new ContentService())->addSaleItem($id, (string) $req->input('sku', ''), (string) $req->input('sale_price', ''), $this->actor());
        return $this->success('Item added to the sale.', "/admin/flash-sales/$id#items");
    }

    public function removeItem(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new ContentService())->removeSaleItem($id, $this->intParam($req, 'vid'), $this->actor());
        return $this->success('Item removed from the sale.', "/admin/flash-sales/$id#items");
    }
}
