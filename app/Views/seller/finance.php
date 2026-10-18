<?php
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Services\SellerLedgerService;

/**
 * @var array<string,string> $balances @var array<string,string> $summary @var list<array<string,mixed>> $entries
 * @var list<array<string,mixed>> $payouts @var string $minimum @var array<string,mixed>|null $method @var bool $isOwner @var array<string,mixed> $seller
 */
$open = array_filter($payouts, fn ($p) => in_array($p['status'], ['requested', 'approved'], true));
$canRequest = $isOwner && $seller['status'] === 'active' && !$open && $method !== null && Money::cmp($balances['available'], $minimum) >= 0;
$statusLabels = ['requested' => ['badge-soft-warning', 'Requested'], 'approved' => ['badge-soft-warning', 'Approved — being paid'], 'paid' => ['badge-soft-success', 'Paid'],
                 'rejected' => ['badge-soft-danger', 'Rejected'], 'cancelled' => ['badge-soft-muted', 'Cancelled']];
?>
<div class="wm-admin-head"><h1>Earnings &amp; payouts</h1></div>

<div class="row g-3 mb-3">
    <?php foreach ([['pending', 'Pending', 'Paid by customers, waiting for delivery and the ' . (int) Settings::int('payout_hold_days', 7) . '-day holding period.'],
                    ['available', 'Available to withdraw', 'You can request this now (minimum ' . Money::format($minimum) . ').'],
                    ['reserved', 'In payout requests', 'Requested and being processed by Waimart.'],
                    ['paid', 'Paid out', 'Sent to you, with a payment reference.']] as [$k, $label, $help]): ?>
        <div class="col-6 col-lg-3">
            <div class="wm-panel h-100"><div class="wm-panel-body">
                <div class="small text-muted"><?= e($label) ?></div>
                <div class="fs-4 fw-bold"><?= e(money($balances[$k])) ?></div>
                <div class="small text-muted"><?= e($help) ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Ledger</div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Date</th><th>Entry</th><th>Reference</th><th>Balance</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $en): ?>
                    <tr>
                        <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $en['created_at']))) ?></td>
                        <td class="small"><?= e(SellerLedgerService::TYPE_LABELS[$en['entry_type']] ?? $en['entry_type']) ?></td>
                        <td class="small"><?= e((string) ($en['sub_number'] ?: $en['payout_number'])) ?></td>
                        <td class="small"><?= e(SellerLedgerService::BUCKET_LABELS[$en['bucket']]) ?></td>
                        <td class="text-end text-nowrap <?= Money::cmp((string) $en['amount'], '0') < 0 ? 'text-danger' : '' ?>"><?= e(money($en['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$entries): ?><tr><td colspan="5" class="text-center text-muted py-3">No entries yet. Earnings appear here once a customer's payment for your items is received.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <div class="wm-panel-body small text-muted">Each line is a permanent record. Moving money between balances (for example from Pending to Available) shows as two lines that cancel out.</div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Request a payout</div>
            <div class="wm-panel-body">
                <p class="small mb-2">Paid to: <strong><?= $method ? e($method['masked']) : 'no payout details on file' ?></strong></p>
                <?php if ($canRequest): ?>
                    <form method="post" action="<?= e(url('/seller/finance/payouts')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <label class="form-label small" for="amount">Amount (KSh)</label>
                        <input class="form-control<?= invalid('amount') ?>" id="amount" name="amount" inputmode="decimal" value="<?= e(fv('amount', $balances['available'])) ?>">
                        <?= field_error('amount') ?>
                        <button class="btn btn-wm-green w-100 mt-2" type="submit">Request payout</button>
                    </form>
                <?php elseif (!$isOwner): ?>
                    <p class="small text-muted mb-0">Only the store owner can request payouts.</p>
                <?php elseif ($open): ?>
                    <p class="small text-muted mb-0">You have a payout request in progress.</p>
                <?php else: ?>
                    <p class="small text-muted mb-0">You can request a payout once at least <?= e(Money::format($minimum)) ?> is available.</p>
                <?php endif; ?>
            </div>
        </section>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Payout requests</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($payouts as $p): [$cls, $lbl] = $statusLabels[$p['status']]; ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between"><strong><?= e(money($p['amount'])) ?></strong><span class="badge <?= $cls ?>"><?= e($lbl) ?></span></div>
                        <div class="text-muted"><?= e($p['payout_number']) ?> · <?= e(date('d M Y', strtotime((string) $p['requested_at']))) ?></div>
                        <?php if ($p['status'] === 'paid'): ?><div>Ref <?= e((string) $p['paid_reference']) ?> · <?= e(date('d M Y', strtotime((string) $p['paid_at']))) ?></div><?php endif; ?>
                        <?php if ($p['status'] === 'rejected' && $p['decision_note']): ?><div class="text-danger"><?= e($p['decision_note']) ?></div><?php endif; ?>
                        <?php if ($p['status'] === 'requested' && $isOwner && $seller['status'] === 'active'): ?>
                            <form method="post" action="<?= e(url('/seller/finance/payouts/' . $p['id'] . '/cancel')) ?>" class="mt-1"><?= csrf_field() ?>
                                <button class="btn btn-link btn-sm p-0" type="submit">Cancel request</button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$payouts): ?><li class="list-group-item text-muted">No payout requests yet.</li><?php endif; ?>
            </ul>
        </section>
        <section class="wm-panel">
            <div class="wm-panel-head">All-time summary</div>
            <dl class="row small wm-panel-body mb-0">
                <dt class="col-7">Sales received</dt><dd class="col-5 text-end"><?= e(money($summary['gross_sales'])) ?></dd>
                <dt class="col-7">Waimart commission</dt><dd class="col-5 text-end">−<?= e(money($summary['commission'])) ?></dd>
                <dt class="col-7">Reversed / refunded</dt><dd class="col-5 text-end"><?= e(money($summary['reversed'])) ?></dd>
                <dt class="col-7">Net earnings</dt><dd class="col-5 text-end fw-bold"><?= e(money($summary['net_earnings'])) ?></dd>
            </dl>
        </section>
    </div>
</div>
