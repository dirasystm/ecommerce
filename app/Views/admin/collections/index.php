<?php
use Waimart\Core\View;
use Waimart\Services\OrderPresenter;

/** @var list<array<string,mixed>> $rows @var int $holdDays @var list<array<string,mixed>> $points */
$req = View::shared('request');
?>
<div class="wm-admin-head"><h1>Collection desk</h1></div>

<form class="wm-panel wm-panel-body mb-3 row g-2 align-items-end" method="get" action="<?= e(url('/admin/collections')) ?>">
    <div class="col-md-5">
        <label class="form-label small" for="q">Order number</label>
        <input class="form-control text-uppercase" id="q" name="q" value="<?= e($req->query['q'] ?? '') ?>" placeholder="WM-XXXXXXXX" autofocus>
    </div>
    <div class="col-md-5">
        <label class="form-label small" for="point">Pickup point</label>
        <select class="form-select" id="point" name="point">
            <option value="">All pickup points</option>
            <?php foreach ($points as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (string) ($req->query['point'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['code']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-secondary" type="submit">Find</button></div>
</form>

<div class="wm-panel">
    <div class="wm-panel-head">Ready for collection <span class="small text-muted fw-normal">Overdue = waiting more than <?= (int) $holdDays ?> days</span></div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Order</th><th>Customer</th><th>Pickup point</th><th>Ready since</th><th>Payment</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= e($r['order_number']) ?></td>
                    <td><?= e(OrderPresenter::firstName($r['customer_name'])) ?></td>
                    <td class="small"><?= e($r['pickup_name']) ?></td>
                    <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($r['ready_at']))) ?><?= (int) $r['overdue'] ? ' <span class="badge badge-soft-danger">Overdue</span>' : '' ?></td>
                    <td class="small"><?= $r['payment_method'] === 'cod' ? '<span class="badge badge-soft-warning">Collect ' . e(money($r['grand_total'])) . ' cash</span>' : e(OrderPresenter::payment($r['payment_status'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-wm-green" href="<?= e(url('/admin/collections/' . $r['id'])) ?>">Hand over</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No orders waiting for collection.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-muted mt-2">Overdue orders can be cancelled from the order page; cancelling releases the reserved stock.</p>
