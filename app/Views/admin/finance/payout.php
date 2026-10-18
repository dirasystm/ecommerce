<?php
use Waimart\Core\Auth;
use Waimart\Services\PayoutService;

/** @var array<string,mixed> $p @var list<array<string,mixed>> $history @var array<string,string> $balances @var array<string,mixed>|null $destination */
$manage = Auth::can('payouts.manage');
?>
<div class="wm-admin-head">
    <h1>Payout <?= e($p['payout_number']) ?></h1>
    <span class="badge fs-6 <?= $p['status'] === 'paid' ? 'badge-soft-success' : (in_array($p['status'], ['rejected', 'cancelled'], true) ? 'badge-soft-danger' : 'badge-soft-warning') ?>"><?= e(ucfirst($p['status'])) ?></span>
</div>
<p><a href="<?= e(url('/admin/payouts', ['status' => $p['status']])) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Payouts</a></p>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Request</div>
            <dl class="row small wm-panel-body mb-0">
                <dt class="col-sm-4">Seller</dt><dd class="col-sm-8"><a href="<?= e(url('/admin/sellers/' . $p['seller_id'] . '/ledger')) ?>"><?= e($p['seller_name']) ?></a> (ledger)</dd>
                <dt class="col-sm-4">Amount</dt><dd class="col-sm-8 fs-5 fw-bold"><?= e(money($p['amount'])) ?></dd>
                <dt class="col-sm-4">Pay to</dt><dd class="col-sm-8"><?= e((string) $p['destination_masked']) ?></dd>
                <dt class="col-sm-4">Requested</dt><dd class="col-sm-8"><?= e(date('d M Y H:i', strtotime((string) $p['requested_at']))) ?> by <?= e(trim($p['requested_first'] . ' ' . $p['requested_last'])) ?></dd>
                <?php if ($p['decided_at']): ?><dt class="col-sm-4">Decided</dt><dd class="col-sm-8"><?= e(date('d M Y H:i', strtotime((string) $p['decided_at']))) ?> by <?= e(trim($p['decided_first'] . ' ' . $p['decided_last'])) ?><?= $p['decision_note'] ? ' — ' . e($p['decision_note']) : '' ?></dd><?php endif; ?>
                <?php if ($p['status'] === 'paid'): ?><dt class="col-sm-4">Paid</dt><dd class="col-sm-8"><?= e(money($p['paid_amount'])) ?> by <?= e(PayoutService::METHODS[$p['paid_method']] ?? '') ?>, ref <strong><?= e($p['paid_reference']) ?></strong>, <?= e(date('d M Y H:i', strtotime((string) $p['paid_at']))) ?> by <?= e(trim($p['paid_first'] . ' ' . $p['paid_last'])) ?></dd><?php endif; ?>
            </dl>
        </section>
        <section class="wm-panel">
            <div class="wm-panel-head">History</div>
            <ol class="list-unstyled small wm-panel-body mb-0">
                <?php foreach ($history as $h): ?><li class="mb-1"><?= e(date('d M Y H:i', strtotime((string) $h['created_at']))) ?> — <?= e(ucfirst($h['to_status'])) ?><?= $h['first_name'] ? ' · ' . e($h['first_name'] . ' ' . $h['last_name']) : '' ?><?= $h['note'] ? '<div class="text-muted">' . e($h['note']) . '</div>' : '' ?></li><?php endforeach; ?>
            </ol>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="wm-panel mb-3"><div class="wm-panel-head">Seller balances now</div>
            <dl class="row small wm-panel-body mb-0">
                <?php foreach (['pending' => 'Pending', 'available' => 'Available', 'reserved' => 'In requests', 'paid' => 'Paid out'] as $k => $l): ?>
                    <dt class="col-7"><?= e($l) ?></dt><dd class="col-5 text-end"><?= e(money($balances[$k])) ?></dd>
                <?php endforeach; ?>
            </dl></section>
        <?php if ($manage && $p['status'] === 'requested'): ?>
            <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/payouts/' . $p['id'] . '/approve')) ?>"><?= csrf_field() ?>
                <div class="wm-panel-head">Approve</div>
                <div class="wm-panel-body"><label class="form-label small" for="note">Internal note <span class="text-muted">(optional)</span></label>
                    <input class="form-control form-control-sm" id="note" name="note" maxlength="500">
                    <button class="btn btn-wm-green w-100 mt-2" type="submit">Approve for payment</button></div></form>
        <?php endif; ?>
        <?php if ($manage && $p['status'] === 'approved'): ?>
            <section class="wm-panel mb-3"><div class="wm-panel-head">Payment details</div>
                <div class="wm-panel-body small">
                    <?php if ($destination): ?>
                        <dl class="row mb-0"><?php foreach ($destination as $k => $v): ?><dt class="col-5"><?= e(ucwords(str_replace('_', ' ', (string) $k))) ?></dt><dd class="col-7"><?= e((string) $v) ?></dd><?php endforeach; ?></dl>
                        <p class="text-muted mt-2 mb-0">This view has been recorded in the audit log.</p>
                    <?php else: ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/payouts/' . $p['id'], ['details' => '1'])) ?>">Show full payment details</a>
                        <p class="text-muted mt-2 mb-0">Opening the details is recorded in the audit log.</p>
                    <?php endif; ?>
                </div></section>
            <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/payouts/' . $p['id'] . '/paid')) ?>" data-confirm="Record this payout as paid? Only do this after the money has actually been sent." novalidate><?= csrf_field() ?>
                <div class="wm-panel-head">Record payment</div>
                <div class="wm-panel-body">
                    <p class="small text-muted">Only after you have actually sent the money.</p>
                    <label class="form-label small" for="paid_method">Paid by</label>
                    <select class="form-select form-select-sm<?= invalid('paid_method') ?>" id="paid_method" name="paid_method"><?php foreach (PayoutService::METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                    <label class="form-label small mt-2" for="paid_reference">Payment reference</label>
                    <input class="form-control form-control-sm<?= invalid('paid_reference') ?>" id="paid_reference" name="paid_reference" maxlength="80" placeholder="e.g. M-Pesa code">
                    <?= field_error('paid_reference') ?>
                    <label class="form-label small mt-2" for="paid_amount">Amount paid (KSh)</label>
                    <input class="form-control form-control-sm<?= invalid('paid_amount') ?>" id="paid_amount" name="paid_amount" inputmode="decimal" value="<?= e((string) $p['amount']) ?>">
                    <?= field_error('paid_amount') ?>
                    <button class="btn btn-wm-green w-100 mt-2" type="submit">Mark as paid</button>
                </div></form>
        <?php endif; ?>
        <?php if ($manage && in_array($p['status'], ['requested', 'approved'], true)): ?>
            <form class="wm-panel" method="post" action="<?= e(url('/admin/payouts/' . $p['id'] . '/reject')) ?>" novalidate><?= csrf_field() ?>
                <div class="wm-panel-head">Reject</div>
                <div class="wm-panel-body"><label class="form-label small" for="reason">Reason (sent to the seller)</label>
                    <input class="form-control form-control-sm<?= invalid('reason') ?>" id="reason" name="reason" maxlength="500">
                    <?= field_error('reason') ?>
                    <button class="btn btn-sm btn-outline-danger mt-2" type="submit">Reject payout</button></div></form>
        <?php endif; ?>
    </div>
</div>
