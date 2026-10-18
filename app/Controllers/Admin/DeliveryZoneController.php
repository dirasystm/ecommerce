<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\FulfilmentAdminService;

final class DeliveryZoneController extends AdminController
{
    public function index(Request $req): Response
    {
        $db = App::db();
        $zones = $db->all('SELECT * FROM delivery_zones ORDER BY sort_order, name');
        $locs = [];
        foreach ($db->all('SELECT l.*, c.name AS county FROM delivery_zone_locations l JOIN counties c ON c.id = l.county_id ORDER BY c.name, l.town') as $l) {
            $locs[(int) $l['zone_id']][] = $l;
        }
        $covered = array_column($db->all("SELECT DISTINCT county_id FROM delivery_zone_locations l JOIN delivery_zones z ON z.id = l.zone_id AND z.is_active = 1"), 'county_id');
        $uncovered = $db->all('SELECT name FROM counties WHERE id NOT IN (' . ($covered ? implode(',', array_map('intval', $covered)) : '0') . ') ORDER BY name');
        return $this->view('delivery/index', ['title' => 'Delivery zones', 'zones' => $zones, 'locs' => $locs, 'uncovered' => array_column($uncovered, 'name')]);
    }

    public function create(Request $req): Response
    {
        return $this->view('delivery/form', ['title' => 'New delivery zone', 'zone' => null, 'locations' => [], 'counties' => $this->counties()]);
    }

    public function edit(Request $req): Response
    {
        $db = App::db();
        $id = $this->intParam($req, 'id');
        $zone = $db->one('SELECT * FROM delivery_zones WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        $locations = $db->all('SELECT l.*, c.name AS county FROM delivery_zone_locations l JOIN counties c ON c.id = l.county_id WHERE l.zone_id = ? ORDER BY c.name, l.town', [$id]);
        return $this->view('delivery/form', ['title' => 'Edit delivery zone', 'zone' => $zone, 'locations' => $locations, 'counties' => $this->counties()]);
    }

    public function store(Request $req): Response
    {
        $id = (new FulfilmentAdminService())->saveZone(null, $req->post, $this->actor());
        return $this->success('Zone created. Now add the counties or towns it covers.', "/admin/delivery-zones/$id/edit");
    }

    public function update(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new FulfilmentAdminService())->saveZone($id, $req->post, $this->actor());
        return $this->success('Zone saved.', "/admin/delivery-zones/$id/edit");
    }

    public function addLocation(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new FulfilmentAdminService())->addZoneLocation($id, $req->post, $this->actor());
        return $this->success('Location added.', "/admin/delivery-zones/$id/edit#locations");
    }

    public function removeLocation(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new FulfilmentAdminService())->removeZoneLocation($id, $this->intParam($req, 'lid'), $this->actor());
        return $this->success('Location removed.', "/admin/delivery-zones/$id/edit#locations");
    }

    /** @return list<array<string,mixed>> */
    private function counties(): array
    {
        return App::db()->all('SELECT id, name FROM counties ORDER BY name');
    }
}
