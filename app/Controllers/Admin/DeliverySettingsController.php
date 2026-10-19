<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\SettingsService;
use Waimart\Services\StatusLabels;

/**
 * Admin → Fulfilment → Delivery settings (delivery.manage): which fulfilment options are offered, store-wide free
 * delivery, the notes customers see at checkout, and the names of order statuses. Zones and stations have their own pages.
 */
final class DeliverySettingsController extends AdminController
{
    public function show(Request $req): Response
    {
        return $this->view('delivery-settings/index', [
            'title' => 'Delivery settings', 'values' => SettingsService::values('delivery'),
            'labels' => StatusLabels::all(), 'edited' => StatusLabels::edited(),
        ]);
    }

    public function save(Request $req): Response
    {
        (new SettingsService())->save($req->post, $this->actor(), 'delivery');
        return $this->success('Delivery settings saved.', '/admin/delivery-settings');
    }

    public function saveLabels(Request $req): Response
    {
        $labels = $req->post['label'] ?? [];
        (new StatusLabels())->save(is_array($labels) ? $labels : [], $this->actor());
        return $this->success('Status names saved.', '/admin/delivery-settings#status-names');
    }
}
