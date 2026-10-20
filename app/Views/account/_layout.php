<?php
/**
 * Account area shell. @var string $section @var string $body @var string $title
 */
use Waimart\Core\Auth;

$u = Auth::user();
$nav = [
    'overview' => ['/account', 'person', 'My account'],
    'orders' => ['/account/orders', 'box-seam', 'Orders'],
    'track' => ['/track-order', 'truck', 'Track order'],
    'wishlist' => ['/account/wishlist', 'heart', 'Wishlist'],
    'reviews' => ['/account/reviews', 'star', 'Reviews'],
    'addresses' => ['/account/addresses', 'geo-alt', 'Addresses'],
    'profile' => ['/account/profile', 'person-vcard', 'Profile'],
    'password' => ['/account/password', 'shield-lock', 'Password'],
    'notifications' => ['/account/notifications', 'bell', 'Notifications'],
];
?>
<div class="container-wm">
    <div class="row g-3 mt-1">
        <aside class="col-lg-3">
            <nav class="wm-card wm-help-nav list-group list-group-flush py-2" aria-label="Account">
                <div class="px-3 py-2 small text-muted">Hi, <strong class="text-body"><?= e($u['first_name']) ?></strong></div>
                <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
                    <a class="list-group-item list-group-item-action<?= $section === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>>
                        <i class="bi bi-<?= $icon ?> me-2" aria-hidden="true"></i><?= e($label) ?>
                    </a>
                <?php endforeach; ?>
                <form action="<?= e(url('/logout')) ?>" method="post" class="px-3 pt-2">
                    <?= csrf_field() ?>
                    <button class="btn btn-link px-0 text-danger small" type="submit"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Sign out</button>
                </form>
            </nav>
        </aside>
        <div class="col-lg-9">
            <section class="wm-card wm-page">
                <h1><?= e($title) ?></h1>
                <?= $body ?>
            </section>
        </div>
    </div>
</div>
