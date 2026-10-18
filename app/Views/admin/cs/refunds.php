<?php
use Waimart\Core\Validator;

/** @var list<array<string,mixed>> $rows @var string $status @var array<string,string> $methods */
?>
<div class="wm-admin-head"><h1>Refunds</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['due' => 'Due', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/refunds', ['status' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php foreach ($rows as $f): ?>
    <section class="wm-panel mb-3">
        <div class="wm-panel-head">
            <span><?= e(money($f['amount'])) ?> · <a href="<?= e(url('/admin/orders/' . $f['order_id'])) ?>"><?= e($f['order_number']) ?></a> · <?= e($f['customer_name']) ?></span>
            <span class="small fw-normal text-muted">Recorded <?= e(date('d M Y', strtotime($f['created_at']))) ?></span>
        </div>
        <div class="wm-panel-body small">
            <p class="mb-1"><strong>Reason:</strong> <?= e($f['reason']) ?></p>
            <p class="mb-2"><strong>Customer phone:</strong> <?= e(Validator::displayPhone((string) $f['customer_phone'])) ?>
                <?= $f['paid_from'] ? ' · <strong>Paid us from:</strong> ' . e(Validator::displayPhone((string) $f['paid_from'])) : '' ?></p>
            <?php if ($f['status'] === 'due'): ?>
                <div class="row g-2">
                    <form class="col-lg-8" method="post" action="<?= e(url('/admin/refunds/' . $f['id'] . '/pay')) ?>" data-confirm="Mark this refund as paid? Only do this after the money has been sent.">
                        <?= csrf_field() ?>
                        <div class="input-group input-group-sm">
                            <select class="form-select" name="method" aria-label="Method" required>
                                <?php foreach ($methods as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                            <input class="form-control" name="reference" required placeholder="Reference, e.g. M-Pesa code" aria-label="Payment reference">
                            <button class="btn btn-wm-green" type="submit">Mark paid</button>
                        </div>
                    </form>
                    <form class="col-lg-4" method="post" action="<?= e(url('/admin/refunds/' . $f['id'] . '/cancel')) ?>" data-confirm="Cancel this refund obligation?">
                        <?= csrf_field() ?>
                        <div class="input-group input-group-sm">
                            <input class="form-control" name="note" required placeholder="Why cancel?" aria-label="Cancellation reason">
                            <button class="btn btn-outline-danger" type="submit">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <p class="mb-0"><?= e(ucfirst($f['status'])) ?><?= $f['paid_at'] ? ' ' . e(date('d M Y H:i', strtotime($f['paid_at']))) . ' via ' . e($methods[$f['method']] ?? (string) $f['method']) . ', ref ' . e((string) $f['reference']) . ' by ' . e((string) $f['paid_by_name']) : '' ?><?= $f['note'] ? ' — ' . e($f['note']) : '' ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="wm-panel wm-panel-body text-center text-muted">No <?= e($status) ?> refunds.</div><?php endif; ?>
