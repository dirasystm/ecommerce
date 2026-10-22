<?php
use Waimart\Core\Validator;
use Waimart\Services\OrderPresenter;

/** @var array<string,mixed> $c @var list<array<string,mixed>> $orders @var list<array<string,mixed>> $addresses @var \Waimart\Core\ListParams $lp */
?>
<div class="wm-admin-head">
    <div><h1><?= e($c['first_name'] . ' ' . $c['last_name']) ?></h1>
        <div class="small text-muted"><?= e($c['email']) ?><?= $c['phone'] ? ' · ' . e(Validator::displayPhone((string) $c['phone'])) : '' ?> · joined <?= e(date('d M Y', strtotime($c['created_at']))) ?></div></div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/customers')) ?>">All customers</a>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <section class="wm-panel"><div class="wm-panel-head">Orders</div>
            <div id="corders" class="wm-table-region" data-table-region data-loading-text="Fetching orders…">
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 wm-num">
                <caption class="visually-hidden">Orders of this customer</caption>
                <thead><tr><th scope="col">Order</th><th scope="col">Status</th><th scope="col">Placed</th><th scope="col" class="text-end">Total</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr><td><a href="<?= e(url('/admin/orders/' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
                        <td><span class="badge <?= OrderPresenter::badgeClass($o['status']) ?>"><?= e(OrderPresenter::status($o['status'])) ?></span></td>
                        <td class="small"><?= e(date('d M Y', strtotime($o['placed_at']))) ?></td><td class="text-end"><?= e(money($o['grand_total'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?><?= empty_row(4, false, 'No orders.') ?><?php endif; ?>
                </tbody>
            </table></div>
            <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/customers/' . $c['id'] . '', 'id' => 'corders', 'noun' => 'orders']) ?>
            </div></section>
    </div>
    <div class="col-lg-4">
        <section class="wm-panel wm-panel-body small">
            <h2 class="fs-6 fw-bold">Account</h2>
            <p class="mb-1">Status: <?= e($c['status']) ?></p>
            <p class="mb-1">Email updates: <?= (int) $c['notify_email'] ? 'on' : 'off' ?> · SMS: <?= (int) $c['notify_sms'] ? 'on' : 'off' ?> · Marketing: <?= (int) $c['marketing_opt_in'] ? 'opted in' : 'no' ?></p>
            <p class="mb-3">Last sign-in: <?= $c['last_login_at'] ? e(date('d M Y H:i', strtotime($c['last_login_at']))) : 'never' ?></p>
            <h2 class="fs-6 fw-bold">Saved addresses</h2>
            <?php foreach ($addresses as $a): ?><p class="mb-1"><?= e($a['location']) ?>, <?= e($a['town']) ?>, <?= e($a['county']) ?><?= (int) $a['is_default'] ? ' <span class="badge badge-soft-success">Default</span>' : '' ?></p><?php endforeach; ?>
            <?php if (!$addresses): ?><p class="text-muted mb-0">None</p><?php endif; ?>
        </section>
    </div>
</div>
