<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** @var list<array<string,mixed>> $orders */
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a class="btn btn-wm btn-sm" href="<?= e(url('/track-order')) ?>"><i class="bi bi-truck" aria-hidden="true"></i> Track an order</a>
    <a class="small" href="<?= e(url('/account/orders/link')) ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i> Add an order placed as a guest</a>
</div>
<?php if ($orders): ?><p class="small text-muted">Open an order below to see where it is, or use <strong>Track an order</strong> with an order or tracking number.</p><?php endif; ?>
<?php if (!$orders): ?>
    <div class="text-center py-4">
        <i class="bi bi-box-seam fs-1 text-muted" aria-hidden="true"></i>
        <p class="mt-2 mb-3">You have not placed any orders yet.</p>
        <a class="btn btn-wm" href="<?= e(url('/')) ?>">Start shopping</a>
    </div>
<?php else: ?>
    <?php foreach ($orders as $o): ?>
        <a class="wm-order-row text-body" href="<?= e(url('/account/orders/' . $o['order_number'])) ?>">
            <img src="<?= e(media($o['image'])) ?>" alt="" width="64" height="64" loading="lazy">
            <span class="flex-grow-1">
                <strong class="d-block"><?= e($o['order_number']) ?></strong>
                <span class="small text-muted"><?= (int) $o['item_count'] ?> item(s) · <?= e(date('j M Y', strtotime($o['placed_at']))) ?> · <?= $o['fulfilment_method'] === 'pickup' ? 'Pickup' : 'Delivery' ?></span>
                <span class="d-block mt-1"><span class="badge <?= OrderPresenter::badgeClass($o['status']) ?>"><?= e(OrderPresenter::status($o['status'])) ?></span></span>
            </span>
            <span class="text-end">
                <span class="fw-bold text-nowrap d-block"><?= e(money($o['grand_total'])) ?></span>
                <span class="small fw-semibold text-nowrap d-inline-block mt-1" style="color:var(--wm-green)"><i class="bi bi-truck" aria-hidden="true"></i> Track order <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
            </span>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
