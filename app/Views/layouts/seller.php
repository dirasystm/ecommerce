<?php
use Waimart\Core\Auth;
use Waimart\Core\View;
use Waimart\Services\SellerContext;

/** Seller Centre shell (same look as the admin, separate navigation and permissions). @var string $content */
$user = Auth::user();
$seller = SellerContext::current();
$req = View::shared('request');
$path = $req instanceof \Waimart\Core\Request ? $req->path : '';
$nav = [
    ['My store', [
        ['/seller', 'speedometer2', 'Dashboard'],
        ['/seller/orders', 'receipt', 'Orders'],
        ['/seller/products', 'box-seam', 'Products'],
        ['/seller/disputes', 'chat-left-text', 'Returns & cases'],
        ['/seller/finance', 'wallet2', 'Earnings & payouts'],
        ['/seller/reports', 'graph-up', 'Sales report'],
        ['/seller/profile', 'shop', 'Store profile'],
    ]],
    ['My account', [
        ['/seller/notifications', 'bell', 'Notifications'],
        ['/account/password', 'shield-lock', 'Password'],
    ]],
];
$active = fn (string $href): bool => $href === '/seller' ? $path === '/seller' : str_starts_with($path, $href);
$renderNav = function () use ($nav, $active, $seller): string {
    ob_start(); ?>
    <div class="px-3 pt-3 pb-2 small">
        <div class="text-muted">Store</div>
        <div class="fw-semibold text-body"><?= e($seller['name'] ?? '') ?></div>
        <?php if (($seller['status'] ?? '') !== 'active'): ?><span class="badge badge-soft-danger mt-1">Suspended</span><?php endif; ?>
    </div>
    <?php foreach ($nav as [$group, $items]): ?>
        <div class="wm-admin-nav-group"><?= e($group) ?></div>
        <?php foreach ($items as [$href, $icon, $label]): ?>
            <a class="wm-admin-nav-link<?= $active($href) ? ' active' : '' ?>" href="<?= e(url($href)) ?>" <?= $active($href) ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><?= e($label) ?>
            </a>
        <?php endforeach;
    endforeach;
    return (string) ob_get_clean();
};
?><!doctype html>
<html lang="en-KE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Seller Centre') . ' | ' . brand_name() . ' Seller Centre') ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<?= \Waimart\Core\View::partial('partials/brand-head', ['ogTitle' => null, 'ogDescription' => null]) ?>
</head>
<body class="wm-admin wm-seller">
<a class="skip-link" href="#main">Skip to main content</a>
<header class="wm-admin-top">
    <button class="btn btn-link text-white d-lg-none p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#wmSellerNav" aria-controls="wmSellerNav" aria-label="Open navigation">
        <i class="bi bi-list fs-3" aria-hidden="true"></i>
    </button>
    <a class="wm-admin-brand" href="<?= e(url('/seller')) ?>">
        <img src="<?= e(\Waimart\Services\Brand::asset('brand_logo_header')) ?>" alt="<?= e(brand_name()) ?>" height="22"><span>Seller Centre</span>
    </a>
    <div class="ms-auto d-flex align-items-center gap-3">
        <?php if ($seller && $seller['status'] === 'active'): ?>
            <a class="text-white small d-none d-sm-inline" href="<?= e(url('/store/' . $seller['slug'])) ?>" target="_blank" rel="noopener">View my store <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
        <?php else: ?>
            <a class="text-white small d-none d-sm-inline" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">View <?= e(brand_name()) ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
        <?php endif; ?>
        <span class="text-white small d-none d-md-inline"><i class="bi bi-person-circle" aria-hidden="true"></i> <?= e(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></span>
        <form action="<?= e(url('/logout')) ?>" method="post" class="m-0">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-light" type="submit">Sign out</button>
        </form>
    </div>
</header>
<div class="wm-admin-body">
    <nav class="wm-admin-side d-none d-lg-block" aria-label="Seller Centre navigation"><?= $renderNav() ?></nav>
    <div class="offcanvas offcanvas-start" tabindex="-1" id="wmSellerNav" aria-label="Seller Centre navigation">
        <div class="offcanvas-header border-bottom"><strong>Menu</strong><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
        <div class="offcanvas-body p-0 wm-admin-side"><?= $renderNav() ?></div>
    </div>
    <main id="main" class="wm-admin-main" tabindex="-1">
        <?php if ($seller && $seller['status'] === 'suspended'): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Your store is suspended.</strong> Your products are hidden from customers and changes are disabled. Your orders and records remain available to view.
                <?php if ($seller['suspension_reason']): ?><div class="small mt-1">Reason: <?= e($seller['suspension_reason']) ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <?= View::partial('partials/flash') ?>
        <?= $content ?>
    </main>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/ui.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
