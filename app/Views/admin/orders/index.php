<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var array<string,int> $counts @var string $status @var string $method @var string $payment */
$tabs = ['' => 'All', 'pending_confirmation' => 'COD to confirm', 'awaiting_payment' => 'Awaiting payment', 'awaiting_verification' => 'To verify',
         'confirmed' => 'Confirmed', 'processing' => 'Processing', 'packed' => 'Packed', 'dispatched' => 'Dispatched', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
?>
<div class="wm-admin-head"><h1>Orders</h1></div>

<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 small">
    <?php foreach ($tabs as $k => $label): ?>
        <li class="nav-item"><a class="nav-link text-nowrap<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/orders', array_filter(['status' => $k]))) ?>">
            <?= e($label) ?><?= $k !== '' && !empty($counts[$k]) ? ' <span class="badge text-bg-light">' . (int) $counts[$k] . '</span>' : '' ?></a></li>
    <?php endforeach; ?>
</ul>

<?= View::partial('partials/table/toolbar', ['id' => 'orders', 'path' => '/admin/orders', 'lp' => $lp, 'label' => 'Search orders', 'placeholder' => 'Order number, phone, name or email',
    'keep' => ['status' => $status],
    'filters' => [
        ['name' => 'method', 'label' => 'Fulfilment', 'value' => $method, 'options' => ['' => 'All methods', 'delivery' => 'Delivery', 'pickup' => 'Pickup']],
        ['name' => 'payment', 'label' => 'Payment status', 'value' => $payment, 'options' => ['' => 'Any payment status', 'unpaid' => 'Unpaid', 'awaiting_verification' => 'To verify',
            'verified' => 'Paid (M-Pesa)', 'cod_pending' => 'Cash due', 'cod_collected' => 'Cash collected', 'rejected' => 'Payment rejected', 'refund_due' => 'Refund due', 'refunded' => 'Refunded']],
    ]]) ?>

<div class="wm-panel">
    <div id="orders" class="wm-table-region" data-table-region data-loading-text="Fetching orders…">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Orders</caption>
            <thead><tr><?= sort_th('Order', 'number', $lp, '/admin/orders') ?><?= sort_th('Placed', 'placed', $lp, '/admin/orders') ?><th scope="col">Customer</th><th scope="col">Method</th><th scope="col">Payment</th><th scope="col">Status</th><th scope="col">Stock held until</th><?= sort_th('Total', 'total', $lp, '/admin/orders', 'text-end') ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $o): ?>
                <tr>
                    <td><a class="fw-semibold" href="<?= e(url('/admin/orders/' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
                    <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($o['placed_at']))) ?></td>
                    <td class="small"><?= e($o['customer_name']) ?></td>
                    <td class="small"><?= $o['fulfilment_method'] === 'pickup' ? 'Pickup' : 'Delivery' ?></td>
                    <td class="small"><?= e(OrderPresenter::payment($o['payment_status'])) ?></td>
                    <td><span class="badge <?= OrderPresenter::badgeClass($o['status']) ?>"><?= e(OrderPresenter::status($o['status'])) ?></span></td>
                    <td class="small text-nowrap"><?= $o['hold_until'] ? e(date('d M H:i', strtotime($o['hold_until']))) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end text-nowrap"><?= e(money($o['grand_total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><?= empty_row(8, $lp->q !== '' || $method !== '' || $payment !== '', 'No orders.', 'No matching orders found. Try changing your search or filters.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/orders', 'id' => 'orders', 'noun' => 'orders']) ?>
    </div>
</div>
