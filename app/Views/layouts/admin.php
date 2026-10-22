<?php
use Waimart\Core\Auth;
use Waimart\Core\View;

/** @var string $content */
$user = Auth::user();
$req = View::shared('request');
$path = $req instanceof \Waimart\Core\Request ? $req->path : '';
$nav = [
    ['Overview', [
        ['/admin', 'speedometer2', 'Dashboard', 'dashboard.view'],
        ['/admin/reports', 'graph-up', 'Reports', 'reports.view'],
    ]],
    ['Sales', [
        ['/admin/orders', 'receipt', 'Orders', 'orders.view'],
        ['/admin/seller-orders', 'diagram-2', 'Seller orders', 'orders.view'],
        ['/admin/customers', 'people', 'Customers', 'customers.view'],
        ['/admin/collections', 'upc-scan', 'Collection desk', 'orders.collect'],
    ]],
    ['Customer service', [
        ['/admin/returns', 'arrow-counterclockwise', 'Returns', 'returns.manage'],
        ['/admin/reviews', 'star', 'Reviews', 'reviews.moderate'],
        ['/admin/notifications', 'envelope', 'Notifications', 'customers.view'],
    ]],
    ['Finance', [
        ['/admin/payments', 'phone', 'M-Pesa payments', 'payments.view'],
        ['/admin/resolutions', 'exclamation-diamond', 'Resolutions', 'resolutions.manage'],
        ['/admin/cod', 'cash-coin', 'Cash on delivery', 'cod.collect'],
        ['/admin/refunds', 'arrow-return-left', 'Refunds', 'refunds.manage'],
        ['/admin/payment-methods', 'credit-card', 'Payment methods', 'payments.configure'],
    ]],
    ['Marketplace', [
        ['/admin/sellers/applications', 'inbox', 'Seller applications', 'sellers.view'],
        ['/admin/sellers', 'shop-window', 'Sellers', 'sellers.view'],
        ['/admin/moderation/products', 'clipboard-check', 'Product approvals', 'products.moderate'],
        ['/admin/disputes', 'chat-left-text', 'Seller cases', 'disputes.manage'],
        ['/admin/reports/marketplace', 'clipboard-data', 'Marketplace report', 'reports.view'],
        ['/admin/payouts', 'wallet2', 'Seller payouts', 'payouts.view'],
        ['/admin/commissions', 'percent', 'Commission', 'commissions.manage'],
    ]],
    ['Fulfilment', [
        ['/admin/delivery-zones', 'truck', 'Delivery zones', 'delivery.manage'],
        ['/admin/pickup-points/applications', 'clipboard-check', 'Station applications', 'pickup_points.approve'],
        ['/admin/pickup-points', 'shop', 'Pickup points', 'pickup_points.manage'],
        ['/admin/delivery-settings', 'sliders', 'Delivery settings', 'delivery.manage'],
    ]],
    ['Catalogue', [
        ['/admin/products', 'box-seam', 'Products', 'catalogue.manage'],
        ['/admin/categories', 'diagram-3', 'Categories', 'catalogue.manage'],
        ['/admin/brands', 'award', 'Brands', 'catalogue.manage'],
        ['/admin/inventory', 'boxes', 'Inventory', 'inventory.view'],
    ]],
    ['Content & marketing', [
        ['/admin/content/pages', 'file-earmark-text', 'Pages', 'content.manage'],
        ['/admin/content/menus', 'list-ul', 'Menus', 'content.manage'],
        ['/admin/content/homepage', 'house', 'Homepage', 'content.manage'],
        ['/admin/banners', 'images', 'Banners', 'content.manage'],
        ['/admin/flash-sales', 'lightning-charge', 'Flash sales', 'content.manage'],
        ['/admin/messages', 'chat-square-text', 'Messages', 'messages.manage'],
    ]],
    ['System', [
        ['/admin/staff', 'person-badge', 'Staff', 'users.manage'],
        ['/admin/roles', 'shield-check', 'Roles & permissions', 'roles.manage'],
        ['/admin/audit', 'journal-text', 'Audit log', 'audit.view'],
        ['/admin/audit/sign-ins', 'door-open', 'Sign-in activity', 'audit.view'],
        ['/admin/account', 'person-gear', 'My account', null],   // every staff member
        ['/admin/settings', 'gear', 'Settings', 'settings.manage'],
        ['/admin/messaging', 'chat-dots', 'Email & SMS', 'messaging.manage'],
        ['/admin/updates', 'cloud-arrow-up', 'Website updates', 'system.update'],
    ]],
];
// Highlight only the most specific matching link (e.g. /admin/sellers/applications, not also /admin/sellers).
$allHrefs = array_merge(...array_map(fn ($g) => array_column($g[1], 0), $nav));
$active = function (string $href) use ($path, $allHrefs): bool {
    if ($href === '/admin') {
        return $path === '/admin';
    }
    if (!str_starts_with($path, $href)) {
        return false;
    }
    foreach ($allHrefs as $other) {
        if (strlen($other) > strlen($href) && str_starts_with($path, $other)) {
            return false;
        }
    }
    return true;
};
$renderNav = function () use ($nav, $active): string {
    ob_start();
    foreach ($nav as [$group, $items]) {
        $visible = array_filter($items, fn ($i) => $i[3] === null || Auth::can($i[3]));
        if (!$visible) {
            continue;
        } ?>
        <div class="wm-admin-nav-group"><?= e($group) ?></div>
        <?php foreach ($visible as [$href, $icon, $label]): ?>
            <a class="wm-admin-nav-link<?= $active($href) ? ' active' : '' ?>" href="<?= e(url($href)) ?>" <?= $active($href) ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><?= e($label) ?>
            </a>
        <?php endforeach;
    }
    return (string) ob_get_clean();
};
?><!doctype html>
<html lang="en-KE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Admin') . ' | ' . brand_name() . ' Admin') ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<?= \Waimart\Core\View::partial('partials/brand-head', ['ogTitle' => null, 'ogDescription' => null]) ?>
</head>
<body class="wm-admin">
<a class="skip-link" href="#main">Skip to main content</a>
<header class="wm-admin-top">
    <button class="btn btn-link text-white d-lg-none p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#wmAdminNav" aria-controls="wmAdminNav" aria-label="Open navigation">
        <i class="bi bi-list fs-3" aria-hidden="true"></i>
    </button>
    <a class="wm-admin-brand" href="<?= e(url('/admin')) ?>">
        <img src="<?= e(\Waimart\Services\Brand::asset('brand_logo_header')) ?>" alt="<?= e(brand_name()) ?>" height="22"><span>Admin</span>
    </a>
    <div class="ms-auto d-flex align-items-center gap-3">
        <a class="text-white small d-none d-sm-inline" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">View store <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
        <a class="text-white small d-none d-md-inline text-decoration-none" href="<?= e(url('/admin/account')) ?>" title="My account: password and two-step sign-in"><i class="bi bi-person-circle" aria-hidden="true"></i> <?= e(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></a>
        <form action="<?= e(url('/logout')) ?>" method="post" class="m-0">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-light" type="submit">Sign out</button>
        </form>
    </div>
</header>
<div class="wm-admin-body">
    <nav class="wm-admin-side d-none d-lg-block" aria-label="Admin navigation"><?= $renderNav() ?></nav>
    <div class="offcanvas offcanvas-start" tabindex="-1" id="wmAdminNav" aria-label="Admin navigation">
        <div class="offcanvas-header border-bottom"><strong>Menu</strong><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
        <div class="offcanvas-body p-0 wm-admin-side"><?= $renderNav() ?></div>
    </div>
    <main id="main" class="wm-admin-main" tabindex="-1">
        <?= View::partial('partials/flash') ?>
        <?= $content ?>
    </main>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
