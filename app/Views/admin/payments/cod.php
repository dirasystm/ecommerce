<?php
use Waimart\Services\OrderPresenter;

/** @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $rows @var list<array<string,mixed>> $recent */
?>
<div class="wm-admin-head"><h1>Cash on delivery</h1></div>
<p class="small text-muted">Record the cash collected for each handed-over COD order. Delivery status and cash collection are tracked separately.</p>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'cod', 'path' => '/admin/cod', 'lp' => $lp, 'label' => 'Search orders with cash to record', 'placeholder' => 'Order number or customer']) ?>
<div class="wm-panel mb-4">
    <div class="wm-panel-head">Cash not yet recorded</div>
    <div id="cod" class="wm-table-region" data-table-region data-loading-text="Loading orders…">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Cash not yet recorded</caption>
            <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Status</th><?= sort_th('Expected', 'total', $lp, '/admin/cod', 'text-end') ?><th scope="col">Record cash</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a class="fw-semibold" href="<?= e(url('/admin/orders/' . $r['id'])) ?>"><?= e($r['order_number']) ?></a></td>
                    <td class="small"><?= e($r['customer_name']) ?></td>
                    <td><span class="badge <?= OrderPresenter::badgeClass($r['status']) ?>"><?= e(OrderPresenter::status($r['status'])) ?></span></td>
                    <td class="text-end fw-semibold text-nowrap"><?= e(money($r['grand_total'])) ?></td>
                    <td>
                        <form class="d-flex gap-1" method="post" action="<?= e(url('/admin/cod/' . $r['id'])) ?>">
                            <?= csrf_field() ?>
                            <input class="form-control form-control-sm" name="amount_collected" inputmode="decimal" required value="<?= e((string) $r['grand_total']) ?>" style="max-width:120px" aria-label="Amount collected">
                            <input class="form-control form-control-sm" name="note" maxlength="300" placeholder="Note (required if different)" aria-label="Note">
                            <button class="btn btn-sm btn-wm-green" type="submit">Record</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><?= empty_row(5, $lp->q !== '', 'No outstanding COD cash.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/cod', 'id' => 'cod', 'noun' => 'orders']) ?>
    </div>
</div>
<div class="wm-panel">
    <div class="wm-panel-head">Recently recorded <span class="small text-muted fw-normal">latest 30; every collection is also on its order and in the audit log</span></div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th scope="col">Order</th><th scope="col" class="text-end">Expected</th><th scope="col" class="text-end">Collected</th><th scope="col">By</th><th scope="col">When</th><th scope="col">Note</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $c): ?>
                <tr<?= \Waimart\Core\Money::cmp((string) $c['amount_collected'], (string) $c['amount_expected']) !== 0 ? ' class="table-warning"' : '' ?>>
                    <td><?= e($c['order_number']) ?></td><td class="text-end"><?= e(money($c['amount_expected'])) ?></td><td class="text-end"><?= e(money($c['amount_collected'])) ?></td>
                    <td class="small"><?= e($c['first_name']) ?></td><td class="small"><?= e(date('d M H:i', strtotime($c['collected_at']))) ?></td><td class="small"><?= e((string) $c['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><?= empty_row(6, false, 'No cash recorded yet.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
