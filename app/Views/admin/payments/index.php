<?php
use Waimart\Core\Validator;
use Waimart\Services\OrderPresenter;
use Waimart\Services\PaymentOptions;

/** @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $rows @var string $tab @var bool $canVerify @var array<string,string> $reasons */
?>
<div class="wm-admin-head"><h1>M-Pesa payments</h1></div>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link<?= $tab !== 'decided' ? ' active' : '' ?>" href="<?= e(url('/admin/payments')) ?>">To verify</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'decided' ? ' active' : '' ?>" href="<?= e(url('/admin/payments', ['tab' => 'decided'])) ?>">Decided</a></li>
</ul>

<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'payments', 'path' => '/admin/payments', 'lp' => $lp, 'label' => 'Search payments',
    'placeholder' => 'M-Pesa code, order number, customer or phone', 'keep' => ['tab' => $tab === 'decided' ? 'decided' : '']]) ?>
<div id="payments" class="wm-table-region" data-table-region data-loading-text="Loading payments…">
<?php if ($tab !== 'decided'): ?>
    <p class="small text-muted">Check each code against the M-Pesa statement for the receiving Till / Paybill / phone. Enter the amount actually received.
        Oldest stock holds are listed first — verify before they expire.</p>
    <?php foreach ($rows as $r): $short = \Waimart\Core\Money::cmp((string) $r['amount_claimed'], (string) $r['grand_total']) < 0; ?>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">
                <span><code class="fs-6"><?= e($r['transaction_code']) ?></code> · <a href="<?= e(url('/admin/orders/' . $r['order_id'])) ?>"><?= e($r['order_number']) ?></a>
                    <span class="badge <?= OrderPresenter::badgeClass($r['order_status']) ?> ms-1"><?= e(OrderPresenter::status($r['order_status'])) ?></span></span>
                <span class="small fw-normal text-muted">Submitted <?= e(date('d M H:i', strtotime($r['submitted_at']))) ?>
                    <?= $r['hold_until'] ? ' · <strong class="text-danger">Stock held until ' . e(date('H:i', strtotime($r['hold_until']))) . '</strong>' : ($r['order_status'] === 'expired' ? ' · <strong class="text-danger">Reservation expired</strong>' : '') ?></span>
            </div>
            <div class="wm-panel-body">
                <div class="row g-3 small">
                    <div class="col-md-4"><span class="text-muted d-block">Method</span><?= e(PaymentOptions::label((string) $r['method'])) ?></div>
                    <div class="col-md-4"><span class="text-muted d-block">Payer phone</span><?= e(Validator::displayPhone((string) $r['payer_phone'])) ?> (<?= e($r['customer_name']) ?>)</div>
                    <div class="col-md-4"><span class="text-muted d-block">Claimed / order total</span><?= e(money($r['amount_claimed'])) ?> / <strong><?= e(money($r['grand_total'])) ?></strong>
                        <?= $short ? '<span class="badge badge-soft-danger ms-1">Short</span>' : '' ?></div>
                </div>
                <?php if ($canVerify): ?>
                    <div class="row g-3 mt-1">
                        <form class="col-lg-6" method="post" action="<?= e(url('/admin/payments/' . $r['id'] . '/verify')) ?>" data-confirm="Verify this payment? The order will be confirmed (or sent to resolution if its reservation expired).">
                            <?= csrf_field() ?>
                            <label class="form-label small" for="amt<?= $r['id'] ?>">Amount received per statement (KSh)</label>
                            <div class="input-group input-group-sm">
                                <input class="form-control" id="amt<?= $r['id'] ?>" name="amount_received" inputmode="decimal" required placeholder="<?= e((string) $r['grand_total']) ?>">
                                <button class="btn btn-wm-green" type="submit">Verify</button>
                            </div>
                            <input class="form-control form-control-sm mt-1" name="note" maxlength="300" placeholder="Note (optional), e.g. statement ref" aria-label="Verification note">
                        </form>
                        <form class="col-lg-6" method="post" action="<?= e(url('/admin/payments/' . $r['id'] . '/reject')) ?>" data-confirm="Reject this payment? The customer will be notified.">
                            <?= csrf_field() ?>
                            <label class="form-label small" for="rr<?= $r['id'] ?>">Reject because…</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select" id="rr<?= $r['id'] ?>" name="reason" required>
                                    <?php foreach ($reasons as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-danger" type="submit">Reject</button>
                            </div>
                            <div class="d-flex gap-1 mt-1">
                                <input class="form-control form-control-sm" name="money_received" inputmode="decimal" placeholder="Money received, if any (KSh)" aria-label="Money received">
                                <input class="form-control form-control-sm" name="note" maxlength="300" placeholder="Note" aria-label="Rejection note">
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="small text-muted mt-2 mb-0">You can view payments but not verify them (requires the payments.verify permission).</p>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="wm-panel"><div class="wm-empty"><i class="bi bi-<?= $lp->q !== '' ? 'search' : 'check2-circle' ?>" aria-hidden="true"></i><div><?= $lp->q !== '' ? 'No matching payments found.' : 'No payments waiting for verification.' ?></div></div></div><?php endif; ?>
    <div class="wm-panel mt-2"><?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/payments', 'id' => 'payments', 'noun' => 'payments']) ?></div>
<?php else: ?>
    <div class="wm-panel">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <caption class="visually-hidden">Decided payments</caption>
                <thead><tr><th scope="col">Code</th><th scope="col">Order</th><th scope="col" class="text-end">Claimed</th><th scope="col" class="text-end">Confirmed</th><th scope="col">Decision</th><th scope="col">Note</th><th scope="col">By</th><th scope="col">When</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr><td><code><?= e($r['transaction_code']) ?></code></td><td><a href="<?= e(url('/admin/orders/' . $r['order_id'])) ?>"><?= e($r['order_number']) ?></a></td>
                        <td class="text-end"><?= e(money($r['amount_claimed'])) ?></td><td class="text-end"><?= $r['amount_confirmed'] !== null ? e(money($r['amount_confirmed'])) : '—' ?></td>
                        <td><span class="badge <?= $r['decision'] === 'verified' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= e((string) $r['decision']) ?></span></td>
                        <td class="small"><?= e((string) $r['decision_note']) ?></td><td class="small"><?= e((string) $r['decided_by']) ?></td>
                        <td class="small text-nowrap"><?= $r['decided_at'] ? e(date('d M H:i', strtotime($r['decided_at']))) : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><?= empty_row(8, $lp->q !== '', 'No decided payments yet.') ?><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/payments', 'id' => 'payments', 'noun' => 'payments']) ?>
    </div>
<?php endif; ?>
</div>
