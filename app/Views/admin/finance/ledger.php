<?php
use Waimart\Core\Money;
use Waimart\Services\SellerLedgerService;

/** @var array<string,mixed> $s @var array<string,string> $balances @var array<string,string> $summary @var list<array<string,mixed>> $entries @var list<array<string,mixed>> $payouts */
?>
<div class="wm-admin-head"><h1>Ledger — <?= e($s['name']) ?></h1></div>
<p><a href="<?= e(url('/admin/sellers/' . $s['id'])) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Seller</a></p>
<div class="row g-3 mb-3">
    <?php foreach (SellerLedgerService::BUCKET_LABELS as $k => $l): ?>
        <div class="col-6 col-lg-3"><div class="wm-panel h-100"><div class="wm-panel-body"><div class="small text-muted"><?= e($l) ?></div><div class="fs-5 fw-bold"><?= e(money($balances[$k])) ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<p class="small text-muted">Sales received <?= e(money($summary['gross_sales'])) ?> · commission <?= e(money($summary['commission'])) ?> · reversed <?= e(money($summary['reversed'])) ?> · net <?= e(money($summary['net_earnings'])) ?>. Balances are calculated from the entries below; nothing is stored separately.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead><tr><th>#</th><th>Date</th><th>Entry</th><th>Balance</th><th>Reference</th><th>Note</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $en): ?>
            <tr><td class="small text-muted"><?= (int) $en['id'] ?></td><td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $en['created_at']))) ?></td>
                <td class="small"><?= e(SellerLedgerService::TYPE_LABELS[$en['entry_type']] ?? $en['entry_type']) ?></td><td class="small"><?= e(SellerLedgerService::BUCKET_LABELS[$en['bucket']]) ?></td>
                <td class="small"><?= e((string) ($en['sub_number'] ?: $en['payout_number'])) ?></td><td class="small text-muted"><?= e((string) $en['note']) ?></td>
                <td class="text-end text-nowrap <?= Money::cmp((string) $en['amount'], '0') < 0 ? 'text-danger' : '' ?>"><?= e(money($en['amount'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?><tr><td colspan="7" class="text-center text-muted py-3">No entries.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
