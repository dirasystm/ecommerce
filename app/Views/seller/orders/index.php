<?php
use Waimart\Services\OrderPresenter;

/** @var \Waimart\Core\ListParams $lp @var string $tab @var list<array<string,mixed>> $rows @var array<string,int> $counts */
$tabs = ['new' => 'New — accept or decline', 'preparing' => 'Preparing', 'handover' => 'Ready to hand over', 'with_platform' => 'With ' . brand_name(),
         'completed' => 'Completed', 'cancelled' => 'Cancelled / declined'];
?>
<div class="wm-admin-head"><h1>Orders</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach ($tabs as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('/seller/orders', ['tab' => $k])) ?>"><?= e($l) ?>
            <span class="badge <?= $k === 'new' && $counts[$k] > 0 ? 'text-bg-warning' : 'text-bg-light' ?>"><?= (int) $counts[$k] ?></span></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">You see orders once the customer has paid (M-Pesa verified) or a cash order has been confirmed. Accept or decline new orders promptly, prepare and pack the items, then hand them to <?= e(brand_name()) ?> for delivery or pickup.</p>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'sorders', 'path' => '/seller/orders', 'lp' => $lp, 'label' => 'Search your orders', 'placeholder' => 'Order number', 'keep' => ['tab' => $tab]]) ?>
<div class="wm-panel"><div id="sorders" class="wm-table-region" data-table-region data-loading-text="Fetching your orders…"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Your orders</caption>
        <thead><tr><th scope="col">Order</th><?= sort_th('Confirmed', 'confirmed', $lp, '/seller/orders') ?><th scope="col">Fulfilment</th><th scope="col" class="text-end">Items</th><?= sort_th('Value', 'value', $lp, '/seller/orders', 'text-end') ?><th scope="col" class="text-end">Your earnings</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(url('/seller/orders/' . $r['id'])) ?>"><?= e($r['sub_number']) ?></a></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $r['confirmed_at']))) ?></td>
                <td class="small"><?= $r['fulfilment_method'] === 'pickup' ? 'Pickup station' : 'Home delivery' ?></td>
                <td class="text-end"><?= (int) $r['units'] ?></td>
                <td class="text-end text-nowrap"><?= e(money($r['items_subtotal'])) ?></td>
                <td class="text-end text-nowrap"><?= e(money($r['seller_net'])) ?></td>
                <td><span class="badge <?= in_array($r['status'], ['cancelled', 'rejected'], true) ? 'badge-soft-danger' : ($r['status'] === 'pending_acceptance' ? 'badge-soft-warning' : 'badge-soft-muted') ?>"><?= e(OrderPresenter::sellerOrderStatus((string) $r['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', 'No orders here.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/seller/orders', 'id' => 'sorders', 'noun' => 'orders']) ?>
</div></div>
<p class="small text-muted mt-2">"Your earnings" is the order value less <?= e(brand_name()) ?>'s commission. It becomes payable only after the customer has received the items and the holding period has passed.</p>
