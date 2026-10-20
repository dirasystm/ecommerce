<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** Track order for signed-in customers. @var string $phone @var list<array<string,mixed>> $orders recent orders */
ob_start(); ?>
<p class="small text-muted" id="trackHelp">Enter the order number (e.g. <code><?= e(\Waimart\Services\Brand::orderPrefix()) ?>-7K3F9Q2X</code>) or the tracking number we sent you when the order was dispatched,
    and the phone number used at checkout. Orders placed by someone else for you can be tracked here too.</p>
<form method="post" action="<?= e(url('/track-order')) ?>" novalidate class="row g-3" style="max-width:560px">
    <?= csrf_field() ?>
    <div class="col-12">
        <label class="form-label" for="order_number">Order or tracking number</label>
        <input class="form-control text-uppercase" id="order_number" name="order_number" required maxlength="80" autocomplete="off" spellcheck="false" aria-describedby="trackHelp" value="<?= e(old('order_number')) ?>">
    </div>
    <div class="col-12">
        <label class="form-label" for="phone">Phone number</label>
        <input class="form-control" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="0712 345 678" value="<?= e(old('phone') ?: $phone) ?>">
    </div>
    <div class="col-12"><button class="btn btn-wm" type="submit"><i class="bi bi-truck" aria-hidden="true"></i> Track order</button></div>
</form>

<?php if ($orders): ?>
    <h2 class="fs-6 fw-bold text-uppercase mt-4 mb-2">Your recent orders</h2>
    <?php foreach ($orders as $o): ?>
        <a class="wm-order-row text-body" href="<?= e(url('/account/orders/' . $o['order_number'])) ?>">
            <img src="<?= e(media($o['image'])) ?>" alt="" width="64" height="64" loading="lazy">
            <span class="flex-grow-1">
                <strong class="d-block"><?= e($o['order_number']) ?></strong>
                <span class="small text-muted"><?= e(date('j M Y', strtotime($o['placed_at']))) ?> · <?= $o['fulfilment_method'] === 'pickup' ? 'Pickup' : 'Delivery' ?></span>
                <span class="d-block mt-1"><span class="badge <?= OrderPresenter::badgeClass($o['status']) ?>"><?= e(OrderPresenter::status($o['status'])) ?></span></span>
            </span>
            <span class="small fw-semibold text-nowrap" style="color:var(--wm-green)">Track order <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
        </a>
    <?php endforeach; ?>
    <p class="small mb-0"><a href="<?= e(url('/account/orders')) ?>">See all your orders</a></p>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
