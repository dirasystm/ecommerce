<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\CatalogueAdminService;

final class CategoryController extends AdminController
{
    public function index(Request $req): Response
    {
        // Default order is the tree (each parent followed by its subcategories); search and filters keep that order.
        $lp = \Waimart\Core\ListParams::from($req, ['tree' => 'COALESCE(p.sort_order, c.sort_order), COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.sort_order, c.name',
            'name' => 'c.name', 'products' => 'product_count'], 'tree', 'asc');
        $status = (string) ($req->query['status'] ?? '');
        $where = ['1=1'];
        $params = [];
        if ($lp->q !== '') {
            $where[] = '(c.name LIKE ? OR c.slug LIKE ? OR p.name LIKE ?)';
            array_push($params, $lp->like(), $lp->like(), $lp->like());
        }
        if (in_array($status, ['active', 'hidden'], true)) {
            $where[] = 'c.is_active = ' . ($status === 'active' ? 1 : 0);
        }
        $rows = $lp->fetch(App::db(), 'SELECT c.*, p.name AS parent_name, (SELECT COUNT(*) FROM products x WHERE x.category_id = c.id) AS product_count',
            'FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE ' . implode(' AND ', $where), $params, 'c.id');
        return $this->view('categories/index', ['title' => 'Categories', 'rows' => $rows, 'lp' => $lp, 'status' => $status]);
    }

    public function create(Request $req): Response
    {
        return $this->form(null);
    }

    public function edit(Request $req): Response
    {
        return $this->form($this->intParam($req, 'id'));
    }

    public function store(Request $req): Response
    {
        (new CatalogueAdminService())->saveCategory(null, $req->post, $this->actor());
        return $this->success('Category created.', '/admin/categories');
    }

    public function update(Request $req): Response
    {
        (new CatalogueAdminService())->saveCategory($this->intParam($req, 'id'), $req->post, $this->actor());
        return $this->success('Category saved.', '/admin/categories');
    }

    private function form(?int $id): Response
    {
        $db = App::db();
        $cat = $id ? $db->one('SELECT * FROM categories WHERE id = ?', [$id]) : null;
        if ($id && $cat === null) {
            throw HttpException::notFound();
        }
        $parents = $db->all('SELECT id, name FROM categories WHERE parent_id IS NULL AND id <> ? ORDER BY sort_order, name', [$id ?? 0]);
        return $this->view('categories/form', ['title' => $cat ? 'Edit category' : 'New category', 'cat' => $cat, 'parents' => $parents]);
    }
}
