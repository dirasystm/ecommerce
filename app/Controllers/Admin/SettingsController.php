<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Settings;
use Waimart\Services\SettingsService;

final class SettingsController extends AdminController
{
    public function edit(Request $req): Response
    {
        $values = [];
        foreach (array_keys(SettingsService::FIELDS) as $k) {
            $values[$k] = (string) Settings::get($k, '');
        }
        $values['product_moderation'] = \Waimart\Services\SellerProductService::moderationRequired() ? '1' : '0';
        return $this->view('settings/edit', ['title' => 'Store settings', 'values' => $values,
            'docModes' => \Waimart\Services\SellerDocumentPolicy::modes()]);
    }

    public function update(Request $req): Response
    {
        (new SettingsService())->save($req->post, $this->actor());
        return $this->success('Settings saved.', '/admin/settings');
    }
}
