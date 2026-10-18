<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Repositories\CatalogueRepository;

final class HomeController extends Controller
{
    /** Web app manifest from the branding settings (so a rebrand needs no file edits). */
    public function manifest(Request $req): Response
    {
        $brand = \Waimart\Services\Brand::class;
        $icon = fn (string $key, int $size) => ['src' => $brand::asset($key), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
        return Response::json([
            'name' => brand_name(),
            'short_name' => mb_substr(brand_name(), 0, 12),
            'description' => $brand::tagline(),
            'start_url' => url('/'),
            'scope' => url('/'),
            'display' => 'standalone',
            'background_color' => '#FFFFFF',
            'theme_color' => $brand::primary(),
            'icons' => [$icon('brand_icon_192', 192), $icon('brand_icon_512', 512)],
        ])->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function index(Request $req): Response
    {
        $repo = new CatalogueRepository(App::db());
        $tree = $repo->categoryTree();

        // One product rail per top category that has products (max 4 rails).
        $rails = [];
        foreach ($tree as $cat) {
            if (count($rails) >= 4) {
                break;
            }
            $products = $repo->inCategoryTree((int) $cat['id'], 12);
            if ($products) {
                $rails[] = ['category' => $cat, 'products' => $products];
            }
        }

        return $this->view('home/index', [
            'banners' => $repo->heroBanners(),
            'flash' => $repo->activeFlashSale(12),
            'deals' => $repo->topDeals(12),
            'featured' => $repo->featured(12),
            'rails' => $rails,
            'tree' => $tree,
        ]);
    }

    /** Public directory of active pickup stations (B3). */
    public function pickupStations(Request $req): Response
    {
        $byCounty = [];
        foreach ((new \Waimart\Services\DeliveryService())->pickupPoints() as $p) {
            $byCounty[$p['county_name']][] = $p;
        }
        return $this->view('pages/pickup-stations', [
            'title' => 'Pickup stations',
            'metaDescription' => 'Find a ' . brand_name() . ' pickup station near you: addresses, opening hours and collection instructions.',
            'byCounty' => $byCounty,
        ]);
    }

    public function categories(Request $req): Response
    {
        return $this->view('home/categories', ['title' => 'All categories']);
    }
}
