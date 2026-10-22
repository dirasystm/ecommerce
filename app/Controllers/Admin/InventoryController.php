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
    public function index(Request $req): Response
    {
        $db = App::db();
        $where = ['1 = 1'];
        $params = [];
        $lp = \Waimart\Core\ListParams::from($req, ['available' => '(i.on_hand - i.reserved)', 'product' => 'p.name', 'sku' => 'v.sku', 'on_hand' => 'i.on_hand'], 'available', 'asc');
        if ($lp->q !== '') {
            $where[] = '(p.name LIKE ? OR v.sku LIKE ? OR v.name LIKE ?)';
            array_push($params, $lp->like(), $lp->like(), $lp->like());
        }
        $filter = (string) ($req->query['filter'] ?? '');
        if ($filter === 'low') {
            $where[] = 'i.on_hand - i.reserved > 0 AND i.on_hand - i.reserved <= i.low_stock_threshold';
        } elseif ($filter === 'out') {
            $where[] = 'i.on_hand - i.reserved <= 0';
        } elseif ($filter === 'reserved') {
            $where[] = 'i.reserved > 0';
        }
        $rows = $lp->fetch($db, 'SELECT v.id, v.sku, v.name AS variant_name, v.is_active, p.id AS product_id, p.name AS product_name,
                    i.on_hand, i.reserved, i.on_hand - i.reserved AS available, i.low_stock_threshold, i.updated_at',
            'FROM inventory i JOIN product_variants v ON v.id = i.variant_id JOIN products p ON p.id = v.product_id WHERE ' . implode(' AND ', $where),
            $params, 'p.name, v.sort_order, v.id');
        return $this->view('inventory/index', ['title' => 'Inventory', 'rows' => $rows, 'lp' => $lp, 'filter' => $filter]);
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
        $lp = \Waimart\Core\ListParams::from($req, ['when' => 'sm.id'], 'when', 'desc');
        $type = (string) ($req->query['type'] ?? '');
        $where = 'sm.variant_id = ?';
        $params = [$vid];
        if (preg_match('/^[a-z_]{1,30}$/', $type)) {
            $where .= ' AND sm.movement_type = ?';
            $params[] = $type;
        }
        if ($lp->q !== '') {
            $where .= " AND (sm.reason LIKE ? OR sm.note LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR sm.order_id = ?)";
            array_push($params, $lp->like(), $lp->like(), $lp->like(), ctype_digit(ltrim($lp->q, '#')) ? (int) ltrim($lp->q, '#') : 0);
        }
        $movements = $lp->fetch($db, 'SELECT sm.*, u.first_name, u.last_name', "FROM stock_movements sm LEFT JOIN users u ON u.id = sm.actor_user_id WHERE $where", $params);
        $types = array_column($db->all('SELECT DISTINCT movement_type FROM stock_movements WHERE variant_id = ? ORDER BY movement_type', [$vid]), 'movement_type');
        return $this->view('inventory/show', [
            'title' => 'Stock: ' . $variant['sku'],
            'v' => $variant,
            'movements' => $movements, 'lp' => $lp, 'type' => $type, 'types' => $types,
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
