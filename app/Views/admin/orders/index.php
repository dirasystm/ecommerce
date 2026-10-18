<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $pages @var array<string,int> $counts @var string $status */
$req = View::shared('request');
$tabs = ['' => 'All', 'pending_confirmation' => 'COD to confirm', 'awaiting_payment' => 'Awaiting payment', 'awaiting_verification' => 'To verify',
         'confirmed' => 'Confirmed', 'processing' => 'Processing', 'packed' => 'Packed', 'dispatched' => 'Dispatched', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
?>
<div class="wm-admin-head"><h1>Orders <span class="text-muted fs-6 fw-normal">(<?= number_format($total) ?>)</span></h1></div>

<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 small">
    <?php foreach ($tabs as $k => $label): ?>
        <li class="nav-item"><a class="nav-link text-nowrap<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/orders', array_filter(['status' => $k]))) ?>">
            <?= e($label) ?><?= $k !== '' && !empty($counts[$k]) ? ' <span class="badge text-bg-light">' . (int) $counts[$k] . '</span>' : '' ?></a></li>
    <?php endforeach; ?>
</ul>

<form class="d-flex gap-2 mb-3" method="get" action="<?= e(url('/admin/orders')) ?>">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <label class="visually-hidden" for="q">Search</label>
    <input class="form-control form-control-sm" id="q" name="q" placeholder="Order number, phone or name" value="<?= e($req->query['q'] ?? '') ?>" style="max-width:320px">
    <select class="form-select form-select-sm" name="method" style="max-width:160px" aria-label="Fulfilment">
        <option value="">All methods</option>
        <option value="delivery" <?= ($req->query['method'] ?? '') === 'delivery' ? 'selected' : '' ?>>Delivery</option>
        <option value="pickup" <?= ($req->query['method'] ?? '') === 'pickup' ? 'selected' : '' ?>>Pickup</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
</form>

<div class="wm-panel">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Order</th><th>Placed</th><th>Customer</th><th>Method</th><th>Payment</th><th>Status</th><th>Stock held until</th><th class="text-end">Total</th></tr></thead>
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
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No orders.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/orders']) ?>
</div>
