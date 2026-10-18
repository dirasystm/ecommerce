<?php
use Waimart\Core\Auth;
use Waimart\Core\View;

$categories = View::shared('categories', []);
$cartCount = (int) View::shared('cart_count', 0);
$user = Auth::user();
$q = '';
$req = View::shared('request');
if ($req instanceof \Waimart\Core\Request) {
    $q = (string) ($req->query['q'] ?? '');
}
$searchForm = function (string $id) use ($q): string {
    ob_start(); ?>
    <form class="wm-search" action="<?= e(url('/search')) ?>" method="get" role="search" data-search>
        <div class="wm-search-field">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="visually-hidden" for="<?= e($id) ?>">Search products, brands and categories</label>
            <input id="<?= e($id) ?>" class="form-control" type="search" name="q" value="<?= e($q) ?>"
                   placeholder="Search products, brands and categories" maxlength="100" autocomplete="off">
        </div>
        <button class="btn btn-wm" type="submit">Search</button>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<div class="wm-topbar">
    <div class="container-wm wm-topbar-inner">
        <div class="wm-promo">
            <span><i class="bi bi-phone" aria-hidden="true"></i>Pay with M-Pesa or Cash on Delivery</span>
            <a href="<?= e(url('/pickup-stations')) ?>"><i class="bi bi-shop" aria-hidden="true"></i>Free pickup at Waimart stations</a>
            <span><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Easy returns</span>
        </div>
        <div class="wm-topbar-links d-none d-lg-flex gap-3">
            <a href="<?= e(url('/sell')) ?>"><i class="bi bi-shop-window" aria-hidden="true"></i> Sell on Waimart</a>
            <a href="<?= e(url('/track-order')) ?>"><i class="bi bi-truck" aria-hidden="true"></i> Track order</a>
            <a href="<?= e(url('/help')) ?>"><i class="bi bi-question-circle" aria-hidden="true"></i> Help</a>
        </div>
    </div>
</div>

<header class="wm-header">
    <div class="container-wm wm-header-inner">
        <button class="btn btn-link text-dark p-1 d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#wmMenu"
                aria-controls="wmMenu" aria-label="Open category menu">
            <i class="bi bi-list fs-3" aria-hidden="true"></i>
        </button>

        <a class="wm-logo" href="<?= e(url('/')) ?>" aria-label="Waimart home">
            <img class="wm-logo-icon" src="<?= e(asset('img/logo-icon.png')) ?>" alt="" width="62" height="40">
            <img class="wm-logo-word" src="<?= e(asset('img/logo-wordmark.png')) ?>" alt="Waimart" width="142" height="28">
        </a>

        <?= $searchForm('wmSearchDesktop') ?>

        <nav class="wm-header-actions" aria-label="Account and cart">
            <div class="dropdown">
                <button class="wm-action-link dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person" aria-hidden="true"></i>
                    <span class="wm-action-label"><?= $user ? 'Hi, ' . e($user['first_name']) : 'Account' ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php if ($user): ?>
                        <li><a class="dropdown-item" href="<?= e(url('/account')) ?>"><i class="bi bi-person"></i>My Account</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('/account/orders')) ?>"><i class="bi bi-box-seam"></i>Orders</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('/account/wishlist')) ?>"><i class="bi bi-heart"></i>Wishlist</a></li>
                        <?php if (\Waimart\Services\SellerContext::current() !== null): ?>
                            <li><a class="dropdown-item" href="<?= e(url('/seller')) ?>"><i class="bi bi-shop-window"></i>Seller Centre</a></li>
                        <?php endif; ?>
                        <?php if ((int) $user['is_staff'] === 1): ?>
                            <li><a class="dropdown-item" href="<?= e(url('/admin')) ?>"><i class="bi bi-speedometer2"></i>Admin dashboard</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="<?= e(url('/logout')) ?>" method="post" class="px-3 py-1">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline-wm w-100" type="submit">Sign out</button>
                            </form>
                        </li>
                    <?php else: ?>
                        <li class="px-3 py-2"><a class="btn btn-wm w-100" href="<?= e(url('/login')) ?>">Sign in</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('/register')) ?>"><i class="bi bi-person-plus"></i>Create account</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(url('/track-order')) ?>"><i class="bi bi-truck"></i>Track an order</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="dropdown d-none d-md-block">
                <button class="wm-action-link dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-question-circle" aria-hidden="true"></i><span class="wm-action-label">Help</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= e(url('/help')) ?>"><i class="bi bi-life-preserver"></i>Help Center</a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/how-to-pay')) ?>"><i class="bi bi-phone"></i>How to pay</a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/delivery-pickup')) ?>"><i class="bi bi-truck"></i>Delivery &amp; pickup</a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/returns-policy')) ?>"><i class="bi bi-arrow-repeat"></i>Returns &amp; refunds</a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/contact')) ?>"><i class="bi bi-chat-dots"></i>Contact us</a></li>
                </ul>
            </div>

            <a class="wm-action-link wm-cart-link" href="<?= e(url('/cart')) ?>">
                <i class="bi bi-cart3" aria-hidden="true"></i>
                <span class="wm-cart-count" data-cart-count <?= $cartCount ? '' : 'hidden' ?>><?= $cartCount ?></span>
                <span class="wm-action-label">Cart</span>
                <span class="visually-hidden"><?= $cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?> in cart</span>
            </a>
        </nav>
    </div>
    <div class="wm-mobile-search"><?= $searchForm('wmSearchMobile') ?></div>
</header>

<!-- Mobile category menu -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="wmMenu" aria-labelledby="wmMenuLabel">
    <div class="offcanvas-header border-bottom">
        <h2 class="offcanvas-title fs-6 fw-bold" id="wmMenuLabel">Shop by category</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div class="accordion accordion-flush" id="wmMenuAcc">
            <?php foreach ($categories as $i => $cat): ?>
                <div class="accordion-item">
                    <?php if ($cat['children']): ?>
                        <h3 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#wmMenuCat<?= $i ?>" aria-expanded="false" aria-controls="wmMenuCat<?= $i ?>">
                                <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?> me-2" aria-hidden="true"></i><?= e($cat['name']) ?>
                            </button>
                        </h3>
                        <div id="wmMenuCat<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#wmMenuAcc">
                            <ul class="list-unstyled ps-5 pb-2 mb-0">
                                <li class="py-1"><a href="<?= e(url('/category/' . $cat['slug'])) ?>">All <?= e($cat['name']) ?></a></li>
                                <?php foreach ($cat['children'] as $child): ?>
                                    <li class="py-1"><a href="<?= e(url('/category/' . $child['slug'])) ?>"><?= e($child['name']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a class="d-block px-3 py-2 text-dark" href="<?= e(url('/category/' . $cat['slug'])) ?>">
                            <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?> me-2" aria-hidden="true"></i><?= e($cat['name']) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="border-top p-3">
            <a class="d-block py-1" href="<?= e(url('/help')) ?>">Help Center</a>
            <a class="d-block py-1" href="<?= e(url('/track-order')) ?>">Track an order</a>
            <a class="d-block py-1" href="<?= e(url('/delivery-pickup')) ?>">Delivery &amp; pickup</a>
            <a class="d-block py-1" href="<?= e(url('/sell')) ?>">Sell on Waimart</a>
        </div>
    </div>
</div>
