<?php
use Waimart\Services\OrderPresenter;

/** @var string $tab @var list<array<string,mixed>> $rows @var array<string,int> $counts */
$tabs = ['new' => 'New — accept or decline', 'preparing' => 'Preparing', 'handover' => 'Ready to hand over', 'with_waimart' => 'With Waimart',
         'completed' => 'Completed', 'cancelled' => 'Cancelled / declined'];
?>
<div class="wm-admin-head"><h1>Orders</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach ($tabs as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('/seller/orders', ['tab' => $k])) ?>"><?= e($l) ?>
            <span class="badge <?= $k === 'new' && $counts[$k] > 0 ? 'text-bg-warning' : 'text-bg-light' ?>"><?= (int) $counts[$k] ?></span></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">You see orders once the customer has paid (M-Pesa verified) or a cash order has been confirmed. Accept or decline new orders promptly, prepare and pack the items, then hand them to Waimart for delivery or pickup.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Order</th><th>Confirmed</th><th>Fulfilment</th><th class="text-end">Items</th><th class="text-end">Value</th><th class="text-end">Your earnings</th><th>Status</th></tr></thead>
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
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No orders here.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<p class="small text-muted mt-2">"Your earnings" is the order value less Waimart's commission. It becomes payable only after the customer has received the items and the holding period has passed.</p>
