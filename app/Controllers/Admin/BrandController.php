<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\CatalogueAdminService;

final class BrandController extends AdminController
{
    public function index(Request $req): Response
    {
        $lp = \Waimart\Core\ListParams::from($req, ['name' => 'b.name', 'products' => 'product_count'], 'name', 'asc');
        $status = (string) ($req->query['status'] ?? '');
        $where = ['1=1'];
        $params = [];
        if ($lp->q !== '') {
            $where[] = '(b.name LIKE ? OR b.slug LIKE ?)';
            array_push($params, $lp->like(), $lp->like());
        }
        if (in_array($status, ['active', 'hidden'], true)) {
            $where[] = 'b.is_active = ' . ($status === 'active' ? 1 : 0);
        }
        $rows = $lp->fetch(App::db(), 'SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) AS product_count',
            'FROM brands b WHERE ' . implode(' AND ', $where), $params, 'b.id');
        return $this->view('brands/index', ['title' => 'Brands', 'rows' => $rows, 'lp' => $lp, 'status' => $status]);
    }

    public function create(Request $req): Response
    {
        return $this->view('brands/form', ['title' => 'New brand', 'brand' => null]);
    }

    public function edit(Request $req): Response
    {
        $brand = App::db()->one('SELECT * FROM brands WHERE id = ?', [$this->intParam($req, 'id')]) ?? throw HttpException::notFound();
        return $this->view('brands/form', ['title' => 'Edit brand', 'brand' => $brand]);
    }

    public function store(Request $req): Response
    {
        (new CatalogueAdminService())->saveBrand(null, $req->post, $this->actor());
        return $this->success('Brand created.', '/admin/brands');
    }

    public function update(Request $req): Response
    {
        (new CatalogueAdminService())->saveBrand($this->intParam($req, 'id'), $req->post, $this->actor());
        return $this->success('Brand saved.', '/admin/brands');
    }
}
