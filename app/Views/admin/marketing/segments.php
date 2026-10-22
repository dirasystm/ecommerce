<?php
use Waimart\Core\View;

/** @var array<string,array{name:string,description:string,count:int}> $summary @var list<array<string,mixed>> $categories @var int $guests */
?>
<div class="wm-admin-head"><h1>Customer segments</h1></div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/segments']) ?>
<p class="small text-muted">Groups worked out live from orders, carts, wishlists and shopping activity of active customers. Thresholds (what counts as "new", "inactive", "high value") are in <a href="<?= e(url('/admin/marketing/settings')) ?>">Settings</a>. A customer can be in several segments.</p>
<div class="row g-3 mb-3">
    <?php foreach ($summary as $code => $s): ?>
        <div class="col-sm-6 col-lg-4 col-xl-3">
            <a class="wm-panel wm-stat h-100 d-block text-decoration-none text-reset" href="<?= e(url('/admin/marketing/segments/' . $code)) ?>">
                <div class="wm-stat-label"><?= e($s['name']) ?></div>
                <div class="wm-stat-value"><?= number_format($s['count']) ?></div>
                <div class="small text-muted"><?= e($s['description']) ?></div>
            </a>
        </div>
    <?php endforeach; ?>
    <div class="col-sm-6 col-lg-4 col-xl-3"><div class="wm-panel wm-stat h-100">
        <div class="wm-stat-label">Guest visitors (30 days)</div>
        <div class="wm-stat-value"><?= number_format($guests) ?></div>
        <div class="small text-muted">Browsers that allowed activity tracking and never signed in (one person may use several).</div>
    </div></div>
</div>
<section class="wm-panel">
    <div class="wm-panel-head">What customers buy (last 180 days)</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Buyers per category</caption>
        <thead><tr><th scope="col">Category</th><th scope="col" class="text-end">Customers</th><th scope="col" class="text-end">Orders</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?><tr><td class="small"><?= e((string) $c['name']) ?></td><td class="text-end small"><?= number_format((int) $c['buyers']) ?></td><td class="text-end small"><?= number_format((int) $c['orders']) ?></td></tr><?php endforeach; ?>
        <?php if (!$categories): ?><tr><td colspan="3" class="text-center text-muted small py-3">No orders from registered customers yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
