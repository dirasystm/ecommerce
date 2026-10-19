<?php
/** @var array<string,mixed> $seller @var array<string,mixed>|null $payout @var array<string,int> $productCounts @var array<string,int> $orderCounts @var array<string,string> $balances @var int $awaitingReply @var list<array{0:string,1:bool,2:?string}> $checklist */
$done = count(array_filter($checklist, fn ($c) => $c[1]));
?>
<div class="wm-admin-head">
    <h1>Welcome, <?= e($seller['name']) ?></h1>
    <?php if ((int) $seller['is_verified'] === 1): ?><span class="badge badge-soft-success"><i class="bi bi-patch-check" aria-hidden="true"></i> Verified seller</span><?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="wm-panel h-100"><div class="wm-panel-body">
            <div class="small text-muted">Store status</div>
            <div class="fs-5 fw-semibold"><?= $seller['status'] === 'active' ? 'Active' : 'Suspended' ?></div>
            <div class="small text-muted">Approved <?= $seller['approved_at'] ? e(date('d M Y', strtotime($seller['approved_at']))) : '—' ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="wm-panel h-100"><div class="wm-panel-body">
            <div class="small text-muted">Products</div>
            <div class="fs-5 fw-semibold"><?= (int) ($productCounts['published'] ?? 0) ?> published <span class="fs-6 fw-normal text-muted">of <?= (int) ($productCounts['all'] ?? 0) ?></span></div>
            <div class="small">
                <a href="<?= e(url('/seller/products', ['status' => 'pending'])) ?>"><?= (int) ($productCounts['pending'] ?? 0) ?> waiting for approval</a> ·
                <a href="<?= e(url('/seller/products', ['status' => 'rejected'])) ?>"><?= (int) ($productCounts['rejected'] ?? 0) ?> need changes</a> ·
                <span class="<?= ($productCounts['low_stock'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>"><?= (int) ($productCounts['low_stock'] ?? 0) ?> low on stock</span>
            </div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="wm-panel h-100"><div class="wm-panel-body">
            <div class="small text-muted">Available to withdraw</div>
            <div class="fs-5 fw-semibold"><?= e(money($balances['available'])) ?></div>
            <div class="small text-muted"><?= e(money($balances['pending'])) ?> pending · paid to <?= $payout ? e($payout['masked']) : 'no payout details yet' ?></div>
            <div class="small"><a href="<?= e(url('/seller/finance')) ?>">Earnings &amp; payouts</a></div>
        </div></div>
    </div>
</div>

<?php if ($awaitingReply > 0): ?>
    <div class="alert alert-warning small"><a href="<?= e(url('/seller/disputes')) ?>"><?= $awaitingReply ?> case<?= $awaitingReply === 1 ? '' : 's' ?> waiting for your reply</a> — earnings for those orders are held until the case is closed.</div>
<?php endif; ?>
<section class="wm-panel mb-3">
    <div class="wm-panel-head">Orders</div>
    <div class="wm-panel-body row g-3 text-center">
        <?php foreach (['new' => 'New — accept or decline', 'preparing' => 'Being prepared', 'handover' => 'Waiting for ' . brand_name() . ' pickup', 'with_platform' => 'With ' . brand_name(), 'completed' => 'Completed'] as $k => $label): ?>
            <div class="col-6 col-md">
                <a class="d-block text-decoration-none" href="<?= e(url('/seller/orders', ['tab' => $k])) ?>">
                    <div class="fs-4 fw-bold <?= $k === 'new' && $orderCounts[$k] > 0 ? 'text-danger' : 'text-body' ?>"><?= (int) $orderCounts[$k] ?></div>
                    <div class="small text-muted"><?= e($label) ?></div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="wm-panel">
    <div class="wm-panel-head">Store setup (<?= $done ?> of <?= count($checklist) ?> done)</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($checklist as [$label, $ok, $href]): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><i class="bi bi-<?= $ok ? 'check-circle-fill text-success' : 'circle text-muted' ?> me-2" aria-hidden="true"></i><?= e($label) ?><span class="visually-hidden"><?= $ok ? ' (done)' : ' (to do)' ?></span></span>
                <?php if (!$ok && $href): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($href)) ?>">Complete</a><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
