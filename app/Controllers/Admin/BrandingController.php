<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Settings;
use Waimart\Services\Brand;
use Waimart\Services\BrandingService;

/** Admin → Settings → Branding (settings.manage). */
final class BrandingController extends AdminController
{
    public function edit(Request $req): Response
    {
        $values = [];
        foreach (['store_name', 'store_tagline', 'order_number_prefix'] as $k) {
            $values[$k] = (string) Settings::get($k, '');
        }
        $values['brand_primary'] = Brand::primary();
        $values['brand_secondary'] = Brand::secondary();
        $values['order_number_prefix'] = Brand::orderPrefix();
        return $this->view('settings/branding', ['title' => 'Branding', 'values' => $values, 'palette' => Brand::palette()]);
    }

    public function update(Request $req): Response
    {
        (new BrandingService())->save($req->post, $req->files, $this->actor());
        return $this->success('Branding saved. The new name, colours and images are live across the shop.', '/admin/settings/branding');
    }
}
