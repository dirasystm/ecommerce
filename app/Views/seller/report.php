<?php
/** @var array<string,mixed> $r @var string $from @var string $to */
?>
<div class="wm-admin-head"><h1>Sales report</h1></div>
<form class="wm-panel wm-panel-body d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(url('/seller/reports')) ?>">
    <div><label class="form-label small mb-0" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label class="form-label small mb-0" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Show</button>
</form>
<div class="row g-3 mb-3">
    <?php foreach ([['Orders', (string) $r['parts'], $r['completed'] . ' completed · ' . $r['declined'] . ' declined · ' . $r['cancelled'] . ' cancelled'],
                    ['Sales received', money($r['sales']), 'Paid by customers for your items'],
                    [brand_name() . ' commission', money($r['commission']), 'Less commission returned on refunds'],
                    ['Your net earnings', money($r['net']), 'After refunds and reversals of ' . money(bcmul($r['adjustments'], '-1', 2))]] as [$label, $value, $help]): ?>
        <div class="col-6 col-lg-3"><div class="wm-panel h-100"><div class="wm-panel-body">
            <div class="small text-muted"><?= e($label) ?></div><div class="fs-5 fw-bold"><?= e($value) ?></div><div class="small text-muted"><?= e($help) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <section class="wm-panel">
            <div class="wm-panel-head">Best sellers (delivered or collected, after returns)</div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Product</th><th class="text-end">Units</th><th class="text-end">Value</th></tr></thead>
                <tbody>
                <?php foreach ($r['top'] as $t): ?>
                    <tr><td><?= e($t['product_name']) ?></td><td class="text-end"><?= (int) $t['units'] ?></td><td class="text-end"><?= e(money($t['value'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$r['top']): ?><tr><td colspan="3" class="text-center text-muted py-3">No completed sales in this period.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="wm-panel">
            <div class="wm-panel-head">After-sales</div>
            <dl class="row small wm-panel-body mb-0">
                <dt class="col-8">Returns requested</dt><dd class="col-4 text-end"><?= (int) $r['returns'] ?></dd>
                <dt class="col-8">Cases opened</dt><dd class="col-4 text-end"><?= (int) $r['cases'] ?></dd>
            </dl>
            <div class="wm-panel-body small text-muted pt-0">Orders are counted by the date they were confirmed; money by the date it was recorded in your ledger.</div>
        </section>
    </div>
</div>
