<?php
/** @var array<string,mixed> $seller @var array<string,mixed>|null $payout @var int $productCount @var list<array{0:string,1:bool,2:?string}> $checklist */
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
            <div class="fs-5 fw-semibold"><?= $productCount ?></div>
            <div class="small text-muted">Product listing opens in the next update of the Seller Centre.</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="wm-panel h-100"><div class="wm-panel-body">
            <div class="small text-muted">Payouts go to</div>
            <div class="fs-6 fw-semibold"><?= $payout ? e($payout['masked']) : 'Not set' ?></div>
            <div class="small text-muted">To change payout details, contact Waimart Seller Support.</div>
        </div></div>
    </div>
</div>

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
