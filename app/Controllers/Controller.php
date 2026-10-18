<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Response;
use Waimart\Core\View;
use Waimart\Repositories\CatalogueRepository;

/**
 * Base storefront controller: shares layout data (category menu, cart count).
 */
abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200, string $layout = 'layouts/main'): Response
    {
        if ($layout === 'layouts/main') {
            self::shareLayoutData();
        }
        return Response::html(View::render($template, $data, $layout), $status);
    }

    public static function shareLayoutData(): void
    {
        if (View::shared('categories') === null) {
            View::share('categories', (new CatalogueRepository(App::db()))->categoryTree());
        }
        if (View::shared('cart_count') === null) {
            View::share('cart_count', \Waimart\Services\CartService::current()->count());
        }
    }
}
