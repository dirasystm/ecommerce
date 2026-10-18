<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Repositories\CatalogueRepository;

final class HomeController extends Controller
{
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
            'metaDescription' => 'Find a Waimart pickup station near you: addresses, opening hours and collection instructions.',
            'byCounty' => $byCounty,
        ]);
    }

    public function categories(Request $req): Response
    {
        return $this->view('home/categories', ['title' => 'All categories']);
    }
}
