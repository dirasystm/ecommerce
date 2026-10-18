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

    /**
     * The homepage is a list of sections (Admin → Content → Homepage): only the live ones are loaded, in their order.
     */
    public function index(Request $req): Response
    {
        $repo = new CatalogueRepository(App::db());
        $content = new \Waimart\Services\SiteContentService();
        $tree = $repo->categoryTree();
        $blocks = [];
        foreach ($content->liveSections() as $s) {
            $limit = (int) ($s['item_limit'] ?? 0);
            $data = match ((string) $s['type']) {
                'hero' => ['banners' => $repo->heroBanners(), 'tiles' => $content->menu('home_side_tiles')],
                'quick_categories' => ['cats' => array_slice($tree, 0, $limit ?: 6)],
                'shop_by_category' => ['cats' => array_slice($tree, 0, $limit ?: 12)],
                'flash_sale' => ['flash' => $repo->activeFlashSale($limit ?: 12)],
                'top_deals' => ['products' => $repo->topDeals($limit ?: 12)],
                'featured' => ['products' => $repo->featured($limit ?: 12)],
                'category_rails' => ['rails' => $this->rails($repo, $tree, $limit ?: 4)],
                'category_row' => ['products' => $s['category_slug'] ? $repo->inCategoryTree((int) $s['category_id'], $limit ?: 12) : []],
                'values' => ['items' => $content->menu('home_values')],
                'text' => ['html' => (new \Waimart\Services\ContentRenderer())->render((string) $s['body'])],
                default => null,
            };
            if ($data !== null) {
                $blocks[] = ['section' => $s, 'data' => $data];
            }
        }
        return $this->view('home/index', ['blocks' => $blocks, 'tree' => $tree]);
    }

    /** One product rail per top category that has products. @return list<array<string,mixed>> */
    private function rails(CatalogueRepository $repo, array $tree, int $max): array
    {
        $rails = [];
        foreach ($tree as $cat) {
            if (count($rails) >= $max) {
                break;
            }
            $products = $repo->inCategoryTree((int) $cat['id'], 12);
            if ($products) {
                $rails[] = ['category' => $cat, 'products' => $products];
            }
        }
        return $rails;
    }

    public function categories(Request $req): Response
    {
        return $this->view('home/categories', ['title' => 'All categories']);
    }
}
