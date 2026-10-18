<?php
use Waimart\Services\OrderPresenter;

/** @var list<array<string,mixed>> $rows */
$reasonLabels = ['payment_after_expiry' => 'Payment verified after reservation expired', 'payment_amount_mismatch' => 'Payment amount mismatch', 'other' => 'Other'];
?>
<div class="wm-admin-head"><h1>Orders requiring resolution</h1></div>
<p class="small text-muted">These customers have paid but their items were released. Re-reserve if stock is available now, or cancel — a refund obligation is recorded automatically. The customer is notified either way.</p>
<?php foreach ($rows as $r): ?>
    <section class="wm-panel mb-3">
        <div class="wm-panel-head">
            <span><a href="<?= e(url('/admin/orders/' . $r['order_id'])) ?>"><?= e($r['order_number']) ?></a> · <?= e($r['customer_name']) ?> · <?= e(money($r['grand_total'])) ?></span>
            <span class="small fw-normal text-muted">Opened <?= e(date('d M H:i', strtotime($r['opened_at']))) ?> · <?= e(OrderPresenter::payment($r['payment_status'])) ?></span>
        </div>
        <div class="wm-panel-body">
            <p class="small mb-1"><strong><?= e($reasonLabels[$r['reason']] ?? $r['reason']) ?></strong><?= $r['details'] ? ' — ' . e($r['details']) : '' ?></p>
            <p class="small text-muted">Stock now: <?= e((string) $r['stock_now']) ?></p>
            <div class="row g-2">
                <form class="col-md-6" method="post" action="<?= e(url('/admin/resolutions/' . $r['order_id'] . '/re-reserve')) ?>" data-confirm="Reserve stock again and confirm this order?">
                    <?= csrf_field() ?>
                    <div class="input-group input-group-sm">
                        <input class="form-control" name="note" maxlength="300" placeholder="Note (optional)" aria-label="Note">
                        <button class="btn btn-wm-green" type="submit">Re-reserve &amp; confirm</button>
                    </div>
                </form>
                <form class="col-md-6" method="post" action="<?= e(url('/admin/resolutions/' . $r['order_id'] . '/cancel')) ?>" data-confirm="Cancel this order and record a refund?">
                    <?= csrf_field() ?>
                    <div class="input-group input-group-sm">
                        <input class="form-control" name="reason" required maxlength="200" placeholder="Reason shown to customer" aria-label="Cancellation reason">
                        <button class="btn btn-outline-danger" type="submit">Cancel &amp; refund</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="wm-panel wm-panel-body text-center text-muted">Nothing to resolve.</div><?php endif; ?>
