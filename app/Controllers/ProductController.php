<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Repositories\ProductRepository;

final class ProductController extends Controller
{
    private const RECENT_MAX = 12;

    public function show(Request $req): Response
    {
        \Waimart\Services\ExpiryService::maybeSweep();   // fallback if the scheduler is not running (C8)
        $repo = new ProductRepository(App::db());
        $product = $repo->findActiveBySlug((string) $req->param('slug'));
        if ($product === null) {
            throw HttpException::notFound('This product is not available.');
        }

        // Recently viewed (session only; excludes the current product when displayed).
        $recent = array_values(array_filter((array) Session::get('recently_viewed', []), 'is_int'));
        $recentCards = $repo->cardsByIds(array_values(array_diff($recent, [(int) $product['id']])));
        array_unshift($recent, (int) $product['id']);
        Session::set('recently_viewed', array_slice(array_values(array_unique($recent)), 0, self::RECENT_MAX));

        // Pre-select a variant from ?v= when valid, otherwise the default.
        $selected = $product['variants'][0];
        $wanted = (int) ($req->query['v'] ?? 0);
        foreach ($product['variants'] as $v) {
            if ($v['id'] === $wanted) {
                $selected = $v;
            }
        }

        $uid = \Waimart\Core\Auth::id();
        $inWishlist = $uid === null ? null
            : (bool) App::db()->value('SELECT 1 FROM wishlist_items WHERE user_id = ? AND product_id = ?', [$uid, $product['id']]);

        return $this->view('catalogue/product', [
            'inWishlist' => $inWishlist,
            'bodyClass' => 'wm-page-product',
            'reviews' => (new \Waimart\Services\ReviewService())->approvedFor((int) $product['id']),
            'title' => $product['name'],
            'metaDescription' => (string) ($product['short_description'] ?: $product['name']),
            'product' => $product,
            'selected' => $selected,
            'related' => $repo->related($product),
            'recent' => array_slice($recentCards, 0, 6),
        ]);
    }
}
