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
$router->get('/category/{slug:[a-z0-9-]+}', [CatalogueController::class, 'category'], [], 'category');
$router->get('/brand/{slug:[a-z0-9-]+}', [CatalogueController::class, 'brand'], [], 'brand');
$router->get('/search', [CatalogueController::class, 'search'], ['throttle:120,60'], 'search');
$router->get('/flash-sales', [CatalogueController::class, 'flashSales'], [], 'flash-sales');
$router->get('/store/{slug:[a-z0-9-]+}', [CatalogueController::class, 'store'], ['throttle:120,60'], 'store');
$router->get('/product/{slug:[a-z0-9-]+}', [ProductController::class, 'show'], [], 'product');
$router->get('/health', [HealthController::class, 'show'], ['throttle:30,60'], 'health');
// Web app manifest (name, colours, icons for "Add to home screen"), generated from the branding settings.
$router->get('/manifest.webmanifest', [HomeController::class, 'manifest']);

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
    $r->get('/orders/{number:[A-Z]{2}-[A-Z0-9]{8}}', [OrderController::class, 'accountShow']);
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

$router->get('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/confirmation', [OrderController::class, 'confirmation']);
$router->get('/track/{number:[A-Za-z0-9-]+}', [OrderController::class, 'track'], ['throttle:60,60']);
$router->post('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/cancel', [OrderController::class, 'cancel'], ['throttle:10,300']);
$router->post('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/payment', [OrderController::class, 'submitPayment'], ['throttle:10,300']);
$router->get('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/return', [OrderController::class, 'returnForm']);
$router->post('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/return', [OrderController::class, 'requestReturn'], ['throttle:10,3600']);
// Seller disputes ("report a problem"): same access as returns (owner or verified guest); cases load only through their order.
$router->get('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/problem', [\Waimart\Controllers\DisputeController::class, 'form']);
$router->post('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/problem', [\Waimart\Controllers\DisputeController::class, 'open'], ['throttle:10,3600']);
$router->get('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/cases/{id:\d+}', [\Waimart\Controllers\DisputeController::class, 'show']);
$router->post('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/cases/{id:\d+}/message', [\Waimart\Controllers\DisputeController::class, 'message'], ['throttle:30,3600']);
$router->post('/order/{number:[A-Z]{2}-[A-Z0-9]{8}}/cases/{id:\d+}/withdraw', [\Waimart\Controllers\DisputeController::class, 'withdraw'], ['throttle:10,3600']);
$router->get('/track-order', [OrderController::class, 'lookupForm'], [], 'track-order');
$router->post('/track-order', [OrderController::class, 'lookup'], ['throttle:20,300']);

// ------------------------------------------------------------------ sell on Waimart (applicants)
use Waimart\Controllers\SellController;

$router->get('/sell', [SellController::class, 'landing'], [], 'sell');
$router->get('/sell/terms', [SellController::class, 'terms']);
$router->group(['prefix' => '/sell', 'middleware' => ['auth']], function ($r) {
    $r->get('/apply', [SellController::class, 'form']);
    $r->post('/apply', [SellController::class, 'save'], ['throttle:30,3600']);
    $r->get('/application', [SellController::class, 'status']);
    $r->post('/application/documents', [SellController::class, 'upload'], ['throttle:30,3600']);
    $r->get('/application/documents/{id:\d+}', [SellController::class, 'download'], ['throttle:60,60']);
    $r->post('/application/documents/{id:\d+}/delete', [SellController::class, 'deleteDocument']);
    $r->post('/application/submit', [SellController::class, 'submit'], ['throttle:10,3600']);
    $r->post('/application/reopen', [SellController::class, 'reopen'], ['throttle:10,3600']);
});

// ------------------------------------------------------------------ Seller Centre (approved sellers)
use Waimart\Controllers\Seller;

$router->group(['prefix' => '/seller', 'middleware' => ['seller']], function ($r) {
    $r->get('/', [Seller\DashboardController::class, 'index'], [], 'seller');
    $r->get('/profile', [Seller\ProfileController::class, 'edit']);
    $r->post('/profile', [Seller\ProfileController::class, 'update'], ['seller_role:owner,manager']);
    $r->get('/products', [Seller\ProductController::class, 'index']);
    $r->get('/products/new', [Seller\ProductController::class, 'create'], ['seller_role:owner,manager']);
    $r->post('/products', [Seller\ProductController::class, 'store'], ['seller_role:owner,manager', 'throttle:60,3600']);
    $r->get('/products/{id:\d+}', [Seller\ProductController::class, 'edit']);
    $r->group(['middleware' => ['seller_role:owner,manager']], function ($r) {
        $r->post('/products/{id:\d+}', [Seller\ProductController::class, 'update']);
        $r->post('/products/{id:\d+}/submit', [Seller\ProductController::class, 'submit']);
        $r->post('/products/{id:\d+}/archive', [Seller\ProductController::class, 'archive']);
        $r->post('/products/{id:\d+}/restore', [Seller\ProductController::class, 'restore']);
        $r->post('/products/{id:\d+}/variants', [Seller\ProductController::class, 'addVariant']);
        $r->post('/products/{id:\d+}/variants/{vid:\d+}', [Seller\ProductController::class, 'updateVariant']);
        $r->post('/products/{id:\d+}/variants/{vid:\d+}/default', [Seller\ProductController::class, 'defaultVariant']);
        $r->post('/products/{id:\d+}/images', [Seller\ProductController::class, 'uploadImage'], ['throttle:60,3600']);
        $r->post('/products/{id:\d+}/images/{iid:\d+}/delete', [Seller\ProductController::class, 'deleteImage']);
        $r->post('/products/{id:\d+}/images/{iid:\d+}/main', [Seller\ProductController::class, 'mainImage']);
    });
    // Stock may be updated by every store member, including staff.
    $r->post('/products/{id:\d+}/variants/{vid:\d+}/stock', [Seller\ProductController::class, 'stock']);
    $r->get('/orders', [Seller\OrderController::class, 'index']);
    $r->get('/orders/{id:\d+}', [Seller\OrderController::class, 'show']);
    // Accept/decline need owner or manager (checked in the controller); any member may mark preparing/packed.
    $r->post('/orders/{id:\d+}/status', [Seller\OrderController::class, 'transition'], ['throttle:120,60']);
    $r->get('/notifications', [Seller\ProfileController::class, 'notifications']);
    $r->post('/notifications', [Seller\ProfileController::class, 'saveNotifications']);
    // Payout requests: owner only (also enforced in the service against the server-side membership).
    $r->get('/disputes', [Seller\DisputeController::class, 'index']);
    $r->get('/disputes/{id:\d+}', [Seller\DisputeController::class, 'show']);
    $r->post('/disputes/{id:\d+}/message', [Seller\DisputeController::class, 'message'], ['seller_role:owner,manager', 'throttle:60,3600']);
    $r->get('/reports', [Seller\FinanceController::class, 'report']);
    $r->get('/finance', [Seller\FinanceController::class, 'index']);
    $r->post('/finance/payouts', [Seller\FinanceController::class, 'request'], ['seller_role:owner', 'throttle:10,3600']);
    $r->post('/finance/payouts/{id:\d+}/cancel', [Seller\FinanceController::class, 'cancel'], ['seller_role:owner', 'throttle:30,3600']);
});

// ------------------------------------------------------------------ staff / admin
use Waimart\Controllers\Admin;

$router->get('/admin/login', [Admin\AuthController::class, 'form'], [], 'admin.login');
$router->post('/admin/login', [Admin\AuthController::class, 'login'], ['throttle:20,300']);
$router->post('/logout', [Admin\AuthController::class, 'logout'], [], 'logout');

$router->group(['prefix' => '/admin', 'middleware' => ['staff']], function ($r) {
    $r->get('/', [Admin\DashboardController::class, 'index'], ['can:dashboard.view'], 'admin');

    $r->get('/moderation/products', [Admin\ProductController::class, 'moderation'], ['can:products.moderate']);
    $r->post('/moderation/products/{id:\d+}/approve', [Admin\ProductController::class, 'approve'], ['can:products.moderate']);
    $r->post('/moderation/products/{id:\d+}/reject', [Admin\ProductController::class, 'reject'], ['can:products.moderate']);

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
    $r->get('/seller-orders', [Admin\OrderController::class, 'sellerOrders'], ['can:orders.view']);
    // The step-specific permission (orders.manage / deliver / cancel) is checked in the controller.
    $r->post('/seller-orders/{id:\d+}/status', [Admin\OrderController::class, 'sellerOrderTransition'], ['can:orders.view']);
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
        // Delivery options, store-wide free delivery, checkout notes and order status names (Basemall Stage 4).
        $r->get('/delivery-settings', [Admin\DeliverySettingsController::class, 'show']);
        $r->post('/delivery-settings', [Admin\DeliverySettingsController::class, 'save']);
        $r->post('/delivery-settings/status-names', [Admin\DeliverySettingsController::class, 'saveLabels']);
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
    // Payment methods offered at checkout and their customer instructions (Basemall Stage 4).
    $r->group(['middleware' => ['can:payments.configure']], function ($r) {
        $r->get('/payment-methods', [Admin\PaymentMethodController::class, 'index']);
        $r->post('/payment-methods/rules', [Admin\PaymentMethodController::class, 'saveRules']);
        $r->get('/payment-methods/{code:mpesa_till|mpesa_paybill|mpesa_phone|cod}', [Admin\PaymentMethodController::class, 'edit']);
        $r->post('/payment-methods/{code:mpesa_till|mpesa_paybill|mpesa_phone|cod}', [Admin\PaymentMethodController::class, 'update']);
    });
    $r->get('/cod', [Admin\PaymentController::class, 'cod'], ['can:cod.collect']);
    $r->post('/cod/{id:\d+}', [Admin\PaymentController::class, 'recordCod'], ['can:cod.collect']);
    $r->get('/settings', [Admin\SettingsController::class, 'index'], ['can:settings.manage']);
    $r->post('/settings', [Admin\SettingsController::class, 'update'], ['can:settings.manage']);
    $r->get('/settings/transfer', [Admin\SettingsController::class, 'transfer'], ['can:settings.manage']);
    $r->get('/settings/export', [Admin\SettingsController::class, 'export'], ['can:settings.manage', 'throttle:30,3600']);
    $r->post('/settings/import', [Admin\SettingsController::class, 'import'], ['can:settings.manage', 'throttle:10,3600']);
    $r->get('/settings/{group:general|contact|social|seo|checkout|orders|payments|delivery|marketplace|email|maintenance}', [Admin\SettingsController::class, 'group'], ['can:settings.manage']);
    $r->post('/settings/{group:general|contact|social|seo|checkout|orders|payments|delivery|marketplace|email|maintenance}', [Admin\SettingsController::class, 'saveGroup'], ['can:settings.manage']);
    $r->post('/settings/email/password', [Admin\SettingsController::class, 'smtpPassword'], ['can:settings.manage', 'can:secrets.manage', 'throttle:20,3600']);
    $r->post('/settings/email/test', [Admin\SettingsController::class, 'testEmail'], ['can:settings.manage', 'throttle:10,3600']);
    $r->get('/settings/branding', [Admin\BrandingController::class, 'edit'], ['can:settings.manage']);
    $r->post('/settings/branding', [Admin\BrandingController::class, 'update'], ['can:settings.manage', 'throttle:30,3600']);

    // Email and SMS wording (Basemall Stage 4).
    $r->group(['middleware' => ['can:messages.manage']], function ($r) {
        $r->get('/messages', [Admin\MessageTemplateController::class, 'index']);
        $r->get('/messages/{code:[a-z_]+}', [Admin\MessageTemplateController::class, 'edit']);
        $r->post('/messages/{code:[a-z_]+}', [Admin\MessageTemplateController::class, 'update']);
        $r->post('/messages/{code:[a-z_]+}/restore', [Admin\MessageTemplateController::class, 'restore']);
    });

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
        // Pages, menus and homepage sections (Stage 3).
        $r->get('/content/pages', [Admin\ContentPagesController::class, 'index']);
        $r->get('/content/pages/new', [Admin\ContentPagesController::class, 'create']);
        $r->post('/content/pages/new', [Admin\ContentPagesController::class, 'save']);
        $r->get('/content/pages/{id:\d+}', [Admin\ContentPagesController::class, 'edit']);
        $r->post('/content/pages/{id:\d+}', [Admin\ContentPagesController::class, 'save']);
        $r->post('/content/pages/{id:\d+}/delete', [Admin\ContentPagesController::class, 'delete']);
        $r->post('/content/pages/{id:\d+}/revisions/{rid:\d+}/restore', [Admin\ContentPagesController::class, 'restore']);
        $r->get('/content/menus', [Admin\MenuController::class, 'index']);
        $r->get('/content/menus/{menu:[a-z0-9_]+}', [Admin\MenuController::class, 'show']);
        $r->post('/content/menus/{menu:[a-z0-9_]+}/title', [Admin\MenuController::class, 'saveTitle']);
        $r->post('/content/menus/{menu:[a-z0-9_]+}/items', [Admin\MenuController::class, 'saveItem']);
        $r->post('/content/menus/{menu:[a-z0-9_]+}/items/{item:\d+}', [Admin\MenuController::class, 'saveItem']);
        $r->post('/content/menus/{menu:[a-z0-9_]+}/items/{item:\d+}/delete', [Admin\MenuController::class, 'deleteItem']);
        $r->post('/content/menus/{menu:[a-z0-9_]+}/items/{item:\d+}/move', [Admin\MenuController::class, 'move']);
        $r->get('/content/homepage', [Admin\HomepageController::class, 'index']);
        $r->get('/content/homepage/new', [Admin\HomepageController::class, 'create']);
        $r->post('/content/homepage/new', [Admin\HomepageController::class, 'save']);
        $r->get('/content/homepage/{id:\d+}', [Admin\HomepageController::class, 'edit']);
        $r->post('/content/homepage/{id:\d+}', [Admin\HomepageController::class, 'save']);
        $r->post('/content/homepage/{id:\d+}/move', [Admin\HomepageController::class, 'move']);
        $r->post('/content/homepage/{id:\d+}/delete', [Admin\HomepageController::class, 'delete']);
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

    $r->group(['middleware' => ['can:sellers.view']], function ($r) {
        $r->get('/sellers/applications', [Admin\SellerController::class, 'applications']);
        $r->get('/sellers/applications/{id:\d+}', [Admin\SellerController::class, 'application']);
        $r->get('/sellers/documents/{id:\d+}', [Admin\SellerController::class, 'document'], ['throttle:120,60']);
        $r->get('/sellers', [Admin\SellerController::class, 'index']);
        $r->get('/sellers/{id:\d+}', [Admin\SellerController::class, 'show']);
    });
    $r->group(['middleware' => ['can:sellers.manage']], function ($r) {
        $r->post('/sellers/applications/{id:\d+}/review', [Admin\SellerController::class, 'startReview']);
        $r->post('/sellers/applications/{id:\d+}/approve', [Admin\SellerController::class, 'approve']);
        $r->post('/sellers/applications/{id:\d+}/reject', [Admin\SellerController::class, 'reject']);
        $r->post('/sellers/{id:\d+}/suspend', [Admin\SellerController::class, 'suspend']);
        $r->post('/sellers/{id:\d+}/reactivate', [Admin\SellerController::class, 'reactivate']);
        $r->post('/sellers/{id:\d+}/verify', [Admin\SellerController::class, 'verify']);
    });

    $r->group(['middleware' => ['can:disputes.manage']], function ($r) {
        $r->get('/disputes', [Admin\DisputeController::class, 'index']);
        $r->get('/disputes/{id:\d+}', [Admin\DisputeController::class, 'show']);
        $r->post('/disputes/{id:\d+}/message', [Admin\DisputeController::class, 'message']);
        $r->post('/disputes/{id:\d+}/status', [Admin\DisputeController::class, 'status']);
        $r->post('/disputes/{id:\d+}/resolve', [Admin\DisputeController::class, 'resolve']);
    });
    $r->get('/reports/marketplace', [Admin\FinanceController::class, 'marketplaceReport'], ['can:reports.view']);

    $r->get('/commissions', [Admin\FinanceController::class, 'commissions'], ['can:commissions.manage']);
    $r->post('/commissions', [Admin\FinanceController::class, 'saveCommission'], ['can:commissions.manage']);
    $r->post('/commissions/{id:\d+}/retire', [Admin\FinanceController::class, 'retireCommission'], ['can:commissions.manage']);
    $r->group(['middleware' => ['can:payouts.view']], function ($r) {
        $r->get('/payouts', [Admin\FinanceController::class, 'payouts']);
        $r->get('/payouts/{id:\d+}', [Admin\FinanceController::class, 'payout']);
        $r->get('/sellers/{id:\d+}/ledger', [Admin\FinanceController::class, 'ledger']);
    });
    $r->group(['middleware' => ['can:payouts.manage']], function ($r) {
        $r->post('/payouts/{id:\d+}/approve', [Admin\FinanceController::class, 'approvePayout']);
        $r->post('/payouts/{id:\d+}/reject', [Admin\FinanceController::class, 'rejectPayout']);
        $r->post('/payouts/{id:\d+}/paid', [Admin\FinanceController::class, 'paidPayout'], ['throttle:60,3600']);
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

// Content pages (Admin → Content → Pages): built-in pages at fixed addresses, others under /pages/{slug}.
$pageSlugs = implode('|', array_map('preg_quote', \Waimart\Services\SiteContentService::SYSTEM_PAGES));
$router->get('/{page:' . $pageSlugs . '}', [PageController::class, 'show'], [], 'page');
$router->get('/pages/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [PageController::class, 'custom']);
