<?php

declare(strict_types=1);

/** @var \Waimart\Core\Router $router */

use Waimart\Controllers\CatalogueController;
use Waimart\Controllers\HealthController;
use Waimart\Controllers\HomeController;
use Waimart\Controllers\PageController;
use Waimart\Controllers\ProductController;

$router->get('/', [HomeController::class, 'index'], [], 'home');
$router->get('/categories', [HomeController::class, 'categories'], [], 'categories');
$router->get('/pickup-stations', [HomeController::class, 'pickupStations'], [], 'pickup-stations');
$router->get('/category/{slug:[a-z0-9-]+}', [CatalogueController::class, 'category'], [], 'category');
$router->get('/brand/{slug:[a-z0-9-]+}', [CatalogueController::class, 'brand'], [], 'brand');
$router->get('/search', [CatalogueController::class, 'search'], ['throttle:120,60'], 'search');
$router->get('/flash-sales', [CatalogueController::class, 'flashSales'], [], 'flash-sales');
$router->get('/product/{slug:[a-z0-9-]+}', [ProductController::class, 'show'], [], 'product');
$router->get('/health', [HealthController::class, 'show'], ['throttle:30,60'], 'health');

// ------------------------------------------------------------------ customers
use Waimart\Controllers\AccountController;
use Waimart\Controllers\AddressController;
use Waimart\Controllers\AuthController;
use Waimart\Controllers\CartController;
use Waimart\Controllers\CheckoutController;
use Waimart\Controllers\OrderController;

$router->get('/login', [AuthController::class, 'loginForm'], ['guest'], 'login');
$router->post('/login', [AuthController::class, 'login'], ['guest', 'throttle:20,300']);
$router->get('/register', [AuthController::class, 'registerForm'], ['guest'], 'register');
$router->post('/register', [AuthController::class, 'register'], ['guest', 'throttle:10,300']);
$router->get('/forgot-password', [AuthController::class, 'forgotForm'], ['guest']);
$router->post('/forgot-password', [AuthController::class, 'forgot'], ['guest', 'throttle:10,300']);
$router->get('/reset-password', [AuthController::class, 'resetForm']);
$router->post('/reset-password', [AuthController::class, 'reset'], ['throttle:10,300']);

$router->group(['prefix' => '/account', 'middleware' => ['auth']], function ($r) {
    $r->get('/', [AccountController::class, 'index'], [], 'account');
    $r->get('/profile', [AccountController::class, 'profile']);
    $r->post('/profile', [AccountController::class, 'saveProfile']);
    $r->get('/password', [AccountController::class, 'password']);
    $r->post('/password', [AccountController::class, 'savePassword'], ['throttle:10,300']);
    $r->get('/notifications', [AccountController::class, 'notifications']);
    $r->post('/notifications', [AccountController::class, 'saveNotifications']);
    $r->get('/orders', [OrderController::class, 'accountIndex']);
    $r->get('/orders/link', [OrderController::class, 'linkForm']);
    $r->post('/orders/link', [OrderController::class, 'linkStart'], ['throttle:10,300']);
    $r->post('/orders/link/verify', [OrderController::class, 'linkVerify'], ['throttle:20,300']);
    $r->post('/orders/link/cancel', [OrderController::class, 'linkCancel']);
    $r->get('/orders/{number:WM-[A-Z0-9]+}', [OrderController::class, 'accountShow']);
    $r->get('/wishlist', [AccountController::class, 'wishlist']);
    $r->get('/reviews', [AccountController::class, 'reviews']);
    $r->post('/reviews/{item:\d+}', [AccountController::class, 'submitReview'], ['throttle:20,3600']);
    $r->get('/addresses', [AddressController::class, 'index']);
    $r->get('/addresses/new', [AddressController::class, 'create']);
    $r->post('/addresses', [AddressController::class, 'store']);
    $r->get('/addresses/{id:\d+}/edit', [AddressController::class, 'edit']);
    $r->post('/addresses/{id:\d+}', [AddressController::class, 'update']);
    $r->post('/addresses/{id:\d+}/delete', [AddressController::class, 'delete']);
    $r->post('/addresses/{id:\d+}/default', [AddressController::class, 'makeDefault']);
});
$router->post('/wishlist/{id:\d+}/toggle', [AccountController::class, 'toggleWishlist'], ['auth']);

$router->get('/cart', [CartController::class, 'show'], [], 'cart');
$router->post('/cart/add', [CartController::class, 'add'], ['throttle:60,60']);
$router->post('/cart/update', [CartController::class, 'update']);
$router->post('/cart/remove', [CartController::class, 'remove']);

$router->get('/checkout', [CheckoutController::class, 'show'], [], 'checkout');
$router->post('/checkout/quote', [CheckoutController::class, 'quote'], ['throttle:120,60']);
$router->get('/checkout/towns', [CheckoutController::class, 'towns'], ['throttle:120,60']);
$router->post('/checkout', [CheckoutController::class, 'submit'], ['throttle:30,60']);

$router->get('/order/{number:WM-[A-Z0-9]+}/confirmation', [OrderController::class, 'confirmation']);
$router->get('/track/{number:[A-Za-z0-9-]+}', [OrderController::class, 'track'], ['throttle:60,60']);
$router->post('/order/{number:WM-[A-Z0-9]{8}}/cancel', [OrderController::class, 'cancel'], ['throttle:10,300']);
$router->post('/order/{number:WM-[A-Z0-9]{8}}/payment', [OrderController::class, 'submitPayment'], ['throttle:10,300']);
$router->get('/order/{number:WM-[A-Z0-9]{8}}/return', [OrderController::class, 'returnForm']);
$router->post('/order/{number:WM-[A-Z0-9]{8}}/return', [OrderController::class, 'requestReturn'], ['throttle:10,3600']);
$router->get('/track-order', [OrderController::class, 'lookupForm'], [], 'track-order');
$router->post('/track-order', [OrderController::class, 'lookup'], ['throttle:20,300']);

// ------------------------------------------------------------------ staff / admin
use Waimart\Controllers\Admin;

$router->get('/admin/login', [Admin\AuthController::class, 'form'], [], 'admin.login');
$router->post('/admin/login', [Admin\AuthController::class, 'login'], ['throttle:20,300']);
$router->post('/logout', [Admin\AuthController::class, 'logout'], [], 'logout');

$router->group(['prefix' => '/admin', 'middleware' => ['staff']], function ($r) {
    $r->get('/', [Admin\DashboardController::class, 'index'], ['can:dashboard.view'], 'admin');

    $r->group(['middleware' => ['can:catalogue.manage']], function ($r) {
        $r->get('/categories', [Admin\CategoryController::class, 'index']);
        $r->get('/categories/new', [Admin\CategoryController::class, 'create']);
        $r->post('/categories', [Admin\CategoryController::class, 'store']);
        $r->get('/categories/{id:\d+}/edit', [Admin\CategoryController::class, 'edit']);
        $r->post('/categories/{id:\d+}', [Admin\CategoryController::class, 'update']);

        $r->get('/brands', [Admin\BrandController::class, 'index']);
        $r->get('/brands/new', [Admin\BrandController::class, 'create']);
        $r->post('/brands', [Admin\BrandController::class, 'store']);
        $r->get('/brands/{id:\d+}/edit', [Admin\BrandController::class, 'edit']);
        $r->post('/brands/{id:\d+}', [Admin\BrandController::class, 'update']);

        $r->get('/products', [Admin\ProductController::class, 'index']);
        $r->get('/products/new', [Admin\ProductController::class, 'create']);
        $r->post('/products', [Admin\ProductController::class, 'store']);
        $r->get('/products/{id:\d+}', [Admin\ProductController::class, 'edit']);
        $r->post('/products/{id:\d+}', [Admin\ProductController::class, 'update']);
        $r->post('/products/{id:\d+}/variants', [Admin\ProductController::class, 'addVariant']);
        $r->post('/products/{id:\d+}/variants/{vid:\d+}', [Admin\ProductController::class, 'updateVariant']);
        $r->post('/products/{id:\d+}/variants/{vid:\d+}/default', [Admin\ProductController::class, 'defaultVariant']);
        $r->post('/products/{id:\d+}/images', [Admin\ProductController::class, 'uploadImage']);
        $r->post('/products/{id:\d+}/images/{iid:\d+}/delete', [Admin\ProductController::class, 'deleteImage']);
        $r->post('/products/{id:\d+}/images/{iid:\d+}/main', [Admin\ProductController::class, 'mainImage']);
    });

    $r->get('/orders', [Admin\OrderController::class, 'index'], ['can:orders.view']);
    $r->get('/orders/{id:\d+}', [Admin\OrderController::class, 'show'], ['can:orders.view']);
    $r->post('/orders/{id:\d+}/status', [Admin\OrderController::class, 'transition'], ['can:orders.view']);

    $r->group(['middleware' => ['can:delivery.manage']], function ($r) {
        $r->get('/delivery-zones', [Admin\DeliveryZoneController::class, 'index']);
        $r->get('/delivery-zones/new', [Admin\DeliveryZoneController::class, 'create']);
        $r->post('/delivery-zones', [Admin\DeliveryZoneController::class, 'store']);
        $r->get('/delivery-zones/{id:\d+}/edit', [Admin\DeliveryZoneController::class, 'edit']);
        $r->post('/delivery-zones/{id:\d+}', [Admin\DeliveryZoneController::class, 'update']);
        $r->post('/delivery-zones/{id:\d+}/locations', [Admin\DeliveryZoneController::class, 'addLocation']);
        $r->post('/delivery-zones/{id:\d+}/locations/{lid:\d+}/delete', [Admin\DeliveryZoneController::class, 'removeLocation']);
    });
    $r->group(['middleware' => ['can:pickup_points.manage']], function ($r) {
        $r->get('/pickup-points', [Admin\PickupPointController::class, 'index']);
        $r->get('/pickup-points/new', [Admin\PickupPointController::class, 'create']);
        $r->post('/pickup-points', [Admin\PickupPointController::class, 'store']);
        $r->get('/pickup-points/{id:\d+}/edit', [Admin\PickupPointController::class, 'edit']);
        $r->post('/pickup-points/{id:\d+}', [Admin\PickupPointController::class, 'update']);
        $r->post('/pickup-points/{id:\d+}/toggle', [Admin\PickupPointController::class, 'toggle']);
    });
    $r->group(['middleware' => ['can:orders.collect']], function ($r) {
        $r->get('/collections', [Admin\CollectionController::class, 'index']);
        $r->get('/collections/{id:\d+}', [Admin\CollectionController::class, 'show']);
        $r->post('/collections/{id:\d+}', [Admin\CollectionController::class, 'verify'], ['throttle:30,300']);
        $r->post('/collections/{id:\d+}/reissue', [Admin\CollectionController::class, 'reissue']);
    });

    $r->get('/payments', [Admin\PaymentController::class, 'index'], ['can:payments.view']);
    $r->post('/payments/{id:\d+}/verify', [Admin\PaymentController::class, 'verify'], ['can:payments.verify']);
    $r->post('/payments/{id:\d+}/reject', [Admin\PaymentController::class, 'reject'], ['can:payments.verify']);
    $r->get('/resolutions', [Admin\PaymentController::class, 'resolutions'], ['can:resolutions.manage']);
    $r->post('/resolutions/{id:\d+}/re-reserve', [Admin\PaymentController::class, 'reReserve'], ['can:resolutions.manage']);
    $r->post('/resolutions/{id:\d+}/cancel', [Admin\PaymentController::class, 'cancelRefund'], ['can:resolutions.manage']);
    $r->get('/cod', [Admin\PaymentController::class, 'cod'], ['can:cod.collect']);
    $r->post('/cod/{id:\d+}', [Admin\PaymentController::class, 'recordCod'], ['can:cod.collect']);
    $r->get('/settings', [Admin\SettingsController::class, 'edit'], ['can:settings.manage']);
    $r->post('/settings', [Admin\SettingsController::class, 'update'], ['can:settings.manage']);

    $r->get('/reports', [Admin\ReportController::class, 'index'], ['can:reports.view']);
    $r->get('/audit', [Admin\AuditController::class, 'index'], ['can:audit.view']);
    $r->group(['middleware' => ['can:users.manage']], function ($r) {
        $r->get('/staff', [Admin\StaffController::class, 'index']);
        $r->get('/staff/new', [Admin\StaffController::class, 'create']);
        $r->post('/staff', [Admin\StaffController::class, 'store']);
        $r->get('/staff/{id:\d+}/edit', [Admin\StaffController::class, 'edit']);
        $r->post('/staff/{id:\d+}/roles', [Admin\StaffController::class, 'updateRoles']);
        $r->post('/staff/{id:\d+}/status', [Admin\StaffController::class, 'status']);
        $r->post('/staff/{id:\d+}/revoke', [Admin\StaffController::class, 'revoke']);
    });
    $r->get('/customers', [Admin\StaffController::class, 'customers'], ['can:customers.view']);
    $r->get('/customers/{id:\d+}', [Admin\StaffController::class, 'customer'], ['can:customers.view']);
    $r->group(['middleware' => ['can:content.manage']], function ($r) {
        $r->get('/banners', [Admin\ContentController::class, 'banners']);
        $r->get('/banners/new', [Admin\ContentController::class, 'bannerForm']);
        $r->post('/banners/new', [Admin\ContentController::class, 'saveBanner']);
        $r->get('/banners/{id:\d+}', [Admin\ContentController::class, 'bannerForm']);
        $r->post('/banners/{id:\d+}', [Admin\ContentController::class, 'saveBanner']);
        $r->post('/banners/{id:\d+}/delete', [Admin\ContentController::class, 'deleteBanner']);
        $r->get('/flash-sales', [Admin\ContentController::class, 'sales']);
        $r->get('/flash-sales/new', [Admin\ContentController::class, 'saleForm']);
        $r->post('/flash-sales/new', [Admin\ContentController::class, 'saveSale']);
        $r->get('/flash-sales/{id:\d+}', [Admin\ContentController::class, 'saleForm']);
        $r->post('/flash-sales/{id:\d+}', [Admin\ContentController::class, 'saveSale']);
        $r->post('/flash-sales/{id:\d+}/items', [Admin\ContentController::class, 'addItem']);
        $r->post('/flash-sales/{id:\d+}/items/{vid:\d+}/delete', [Admin\ContentController::class, 'removeItem']);
    });

    $r->get('/returns', [Admin\CustomerServiceController::class, 'returns'], ['can:returns.manage']);
    $r->get('/returns/{id:\d+}', [Admin\CustomerServiceController::class, 'returnShow'], ['can:returns.manage']);
    $r->post('/returns/{id:\d+}', [Admin\CustomerServiceController::class, 'returnAction'], ['can:returns.manage']);
    $r->get('/refunds', [Admin\CustomerServiceController::class, 'refunds'], ['can:refunds.manage']);
    $r->post('/refunds/{id:\d+}/pay', [Admin\CustomerServiceController::class, 'refundPay'], ['can:refunds.manage']);
    $r->post('/refunds/{id:\d+}/cancel', [Admin\CustomerServiceController::class, 'refundCancel'], ['can:refunds.manage']);
    $r->get('/reviews', [Admin\CustomerServiceController::class, 'reviews'], ['can:reviews.moderate']);
    $r->post('/reviews/{id:\d+}', [Admin\CustomerServiceController::class, 'moderate'], ['can:reviews.moderate']);
    $r->get('/notifications', [Admin\CustomerServiceController::class, 'notifications'], ['can:customers.view']);
    $r->post('/notifications/{id:\d+}/resend', [Admin\CustomerServiceController::class, 'resend'], ['can:customers.view']);

    $r->get('/inventory', [Admin\InventoryController::class, 'index'], ['can:inventory.view']);
    $r->get('/inventory/{vid:\d+}', [Admin\InventoryController::class, 'show'], ['can:inventory.view']);
    $r->post('/inventory/{vid:\d+}/receive', [Admin\InventoryController::class, 'receive'], ['can:inventory.adjust']);
    $r->post('/inventory/{vid:\d+}/adjust', [Admin\InventoryController::class, 'adjust'], ['can:inventory.adjust']);
    $r->post('/inventory/{vid:\d+}/count', [Admin\InventoryController::class, 'count'], ['can:inventory.adjust']);
});

// Static content pages (whitelisted slugs only)
$pageSlugs = implode('|', array_map('preg_quote', array_keys(PageController::PAGES)));
$router->get('/{page:' . $pageSlugs . '}', [PageController::class, 'show'], [], 'page');
