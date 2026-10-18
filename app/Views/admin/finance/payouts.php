<?php
/** @var string $status @var list<array<string,mixed>> $rows @var array<string,int> $counts @var array<string,mixed> $liability */
$labels = ['requested' => 'Requested', 'approved' => 'Approved — to pay', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
?>
<div class="wm-admin-head">
    <h1>Seller payouts</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/payouts', ['status' => $status, 'format' => 'csv'])) ?>"><i class="bi bi-download" aria-hidden="true"></i> CSV</a>
</div>
<div class="row g-3 mb-3">
    <?php foreach ([['pending', 'Earned, not yet payable'], ['available', 'Payable, not requested'], ['reserved', 'In payout requests'], ['paid', 'Paid out (all time)']] as [$k, $l]): ?>
        <div class="col-6 col-lg-3"><div class="wm-panel h-100"><div class="wm-panel-body">
            <div class="small text-muted"><?= e($l) ?></div><div class="fs-5 fw-bold"><?= e(money($liability[$k])) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>
<p class="small text-muted">Owed to sellers right now: <strong><?= e(money(bcadd(bcadd((string) $liability['pending'], (string) $liability['available'], 2), (string) $liability['reserved'], 2))) ?></strong> (pending + payable + requested). Payouts are paid manually; record each one with its payment reference.</p>
<ul class="nav nav-pills mb-3 small">
    <?php foreach ($labels as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/payouts', ['status' => $k])) ?>"><?= e($l) ?> <span class="badge text-bg-light"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Payout</th><th>Seller</th><th class="text-end">Amount</th><th>Requested</th><th>To</th><th>Reference</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr><td><code><?= e($p['payout_number']) ?></code></td><td><?= e($p['seller_name']) ?></td><td class="text-end fw-semibold"><?= e(money($p['amount'])) ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $p['requested_at']))) ?></td><td class="small"><?= e((string) $p['destination_masked']) ?></td>
                <td class="small"><?= e((string) $p['paid_reference']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/payouts/' . $p['id'])) ?>">Open</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing here.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
