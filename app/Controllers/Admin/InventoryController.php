<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\InventoryService;

final class InventoryController extends AdminController
{
    private const PER_PAGE = 50;

    public function index(Request $req): Response
    {
        $db = App::db();
        $where = ['1 = 1'];
        $params = [];
        $q = trim((string) ($req->query['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $where[] = '(p.name LIKE ? OR v.sku LIKE ?)';
            array_push($params, $like, $like);
        }
        $filter = (string) ($req->query['filter'] ?? '');
        if ($filter === 'low') {
            $where[] = 'i.on_hand - i.reserved > 0 AND i.on_hand - i.reserved <= i.low_stock_threshold';
        } elseif ($filter === 'out') {
            $where[] = 'i.on_hand - i.reserved <= 0';
        } elseif ($filter === 'reserved') {
            $where[] = 'i.reserved > 0';
        }
        $w = implode(' AND ', $where);
        $total = (int) $db->value("SELECT COUNT(*) FROM inventory i JOIN product_variants v ON v.id = i.variant_id JOIN products p ON p.id = v.product_id WHERE $w", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($req->query['page'] ?? 1)));
        $rows = $db->all(
            "SELECT v.id, v.sku, v.name AS variant_name, v.is_active, p.id AS product_id, p.name AS product_name,
                    i.on_hand, i.reserved, i.on_hand - i.reserved AS available, i.low_stock_threshold, i.updated_at
               FROM inventory i JOIN product_variants v ON v.id = i.variant_id JOIN products p ON p.id = v.product_id
              WHERE $w ORDER BY (i.on_hand - i.reserved) ASC, p.name, v.sort_order
              LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        return $this->view('inventory/index', ['title' => 'Inventory', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'filter' => $filter]);
    }

    public function show(Request $req): Response
    {
        $db = App::db();
        $vid = $this->intParam($req, 'vid');
        $variant = $db->one(
            'SELECT v.*, p.name AS product_name, p.id AS product_id, i.on_hand, i.reserved, i.low_stock_threshold
               FROM product_variants v JOIN products p ON p.id = v.product_id JOIN inventory i ON i.variant_id = v.id
              WHERE v.id = ?',
            [$vid]
        ) ?? throw HttpException::notFound();
        $movements = $db->all(
            'SELECT sm.*, u.first_name, u.last_name FROM stock_movements sm LEFT JOIN users u ON u.id = sm.actor_user_id
              WHERE sm.variant_id = ? ORDER BY sm.id DESC LIMIT 100',
            [$vid]
        );
        return $this->view('inventory/show', [
            'title' => 'Stock: ' . $variant['sku'],
            'v' => $variant,
            'movements' => $movements,
            'canAdjust' => Auth::can('inventory.adjust'),
        ]);
    }

    public function receive(Request $req): Response
    {
        $vid = $this->intParam($req, 'vid');
        $qty = (string) $req->input('quantity', '');
        $after = (new InventoryService())->receive($vid, ctype_digit($qty) ? (int) $qty : 0, (string) $req->input('reason', ''), (string) $req->input('note', ''), $this->actor());
        return $this->success("Stock received. On hand is now {$after['on_hand']}.", "/admin/inventory/$vid");
    }

    public function adjust(Request $req): Response
    {
        $vid = $this->intParam($req, 'vid');
        $delta = (string) $req->input('delta', '');
        $after = (new InventoryService())->adjust($vid, preg_match('/^-?\d{1,6}$/', $delta) ? (int) $delta : 0, (string) $req->input('reason', ''), (string) $req->input('note', ''), $this->actor());
        return $this->success("Stock adjusted. On hand is now {$after['on_hand']}.", "/admin/inventory/$vid");
    }

    public function count(Request $req): Response
    {
        $vid = $this->intParam($req, 'vid');
        $counted = (string) $req->input('counted', '');
        if (!ctype_digit($counted)) {
            throw new \Waimart\Core\ValidationException(['counted' => 'Enter the counted quantity as a whole number.']);
        }
        $after = (new InventoryService())->setCount($vid, (int) $counted, (string) $req->input('note', ''), $this->actor());
        return $this->success("Stock count recorded. On hand is now {$after['on_hand']}.", "/admin/inventory/$vid");
    }
}
