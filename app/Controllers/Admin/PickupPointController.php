<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\FulfilmentAdminService;

final class PickupPointController extends AdminController
{
    public function index(Request $req): Response
    {
        $where = ['1 = 1'];
        $params = [];
        $lp = \Waimart\Core\ListParams::from($req, ['location' => 'p.is_active DESC, c.name, p.town', 'name' => 'p.name', 'code' => 'p.code', 'waiting' => 'awaiting_collection'], 'location', 'asc');
        if ($lp->q !== '') {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.town LIKE ? OR c.name LIKE ?)';
            array_push($params, $lp->like(), $lp->like(), $lp->like(), $lp->like());
        }
        $status = (string) ($req->query['status'] ?? '');
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'p.is_active = ' . ($status === 'active' ? 1 : 0);
        }
        $rows = $lp->fetch(App::db(), "SELECT p.*, c.name AS county, (SELECT COUNT(*) FROM order_fulfilment f JOIN orders o ON o.id = f.order_id
                      WHERE f.pickup_point_id = p.id AND o.status = 'ready_for_collection') AS awaiting_collection",
            'FROM pickup_points p JOIN counties c ON c.id = p.county_id WHERE ' . implode(' AND ', $where), $params, 'p.name, p.id');
        return $this->view('pickup/index', ['title' => 'Pickup points', 'rows' => $rows, 'status' => $status, 'lp' => $lp]);
    }

    public function create(Request $req): Response
    {
        return $this->view('pickup/form', ['title' => 'New pickup point', 'point' => null, 'counties' => $this->counties(), 'openOrders' => 0]);
    }

    public function edit(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $point = App::db()->one('SELECT * FROM pickup_points WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        return $this->view('pickup/form', [
            'title' => 'Edit pickup point', 'point' => $point, 'counties' => $this->counties(),
            'openOrders' => (new FulfilmentAdminService())->openOrdersAt($id),
        ]);
    }

    public function store(Request $req): Response
    {
        (new FulfilmentAdminService())->savePickupPoint(null, $req->post, $this->actor());
        return $this->success('Pickup point created.', '/admin/pickup-points');
    }

    public function update(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new FulfilmentAdminService())->savePickupPoint($id, $req->post, $this->actor());
        return $this->success('Pickup point saved. Existing orders keep the details they were placed with.', "/admin/pickup-points/$id/edit");
    }

    public function toggle(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $active = (string) $req->input('active', '') === '1';
        $open = (new FulfilmentAdminService())->setPickupActive($id, $active, $this->actor());
        if (!$active && $open > 0) {
            Session::flash('warning', "Pickup point deactivated. It is hidden from checkout, but $open open order(s) are still assigned to it — "
                . 'those customers can still collect there unless you contact them.');
            return \Waimart\Core\Response::redirect('/admin/pickup-points');
        }
        return $this->success($active ? 'Pickup point activated.' : 'Pickup point deactivated and hidden from checkout.', '/admin/pickup-points');
    }

    /** @return list<array<string,mixed>> */
    private function counties(): array
    {
        return App::db()->all('SELECT id, name FROM counties ORDER BY name');
    }
}
