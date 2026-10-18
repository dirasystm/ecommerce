<?php
use Waimart\Core\Auth;
use Waimart\Core\Validator;
use Waimart\Core\View;

/** @var array<string,mixed>|null $default @var int $orderCount @var int $wishCount */
$u = Auth::user();
ob_start(); ?>
<div class="row g-3">
    <div class="col-md-6">
        <div class="border rounded p-3 h-100">
            <h2 class="fs-6 text-uppercase text-muted">Account details</h2>
            <p class="mb-1 fw-semibold"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></p>
            <p class="mb-1 small"><?= e($u['email']) ?></p>
            <p class="mb-2 small"><?= e($u['phone'] ? Validator::displayPhone($u['phone']) : '') ?></p>
            <a class="small" href="<?= e(url('/account/profile')) ?>">Edit details</a>
        </div>
    </div>
    <div class="col-md-6">
        <div class="border rounded p-3 h-100">
            <h2 class="fs-6 text-uppercase text-muted">Default delivery address</h2>
            <?php if ($default): ?>
                <p class="mb-1 fw-semibold"><?= e($default['recipient_name']) ?></p>
                <p class="mb-2 small"><?= e($default['location']) ?>, <?= e($default['town']) ?>, <?= e($default['county_name']) ?></p>
                <a class="small" href="<?= e(url('/account/addresses')) ?>">Manage addresses</a>
            <?php else: ?>
                <p class="small text-muted">No address saved yet.</p>
                <a class="small" href="<?= e(url('/account/addresses/new')) ?>">Add an address</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-3"><a class="border rounded p-3 d-block text-body" href="<?= e(url('/account/orders')) ?>"><span class="d-block fs-4 fw-bold"><?= $orderCount ?></span><span class="small text-muted">Orders</span></a></div>
    <div class="col-6 col-md-3"><a class="border rounded p-3 d-block text-body" href="<?= e(url('/account/wishlist')) ?>"><span class="d-block fs-4 fw-bold"><?= $wishCount ?></span><span class="small text-muted">Saved items</span></a></div>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
