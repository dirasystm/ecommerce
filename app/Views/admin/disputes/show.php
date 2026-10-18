<?php
use Waimart\Core\Validator;
use Waimart\Services\DisputeService;
use Waimart\Services\OrderPresenter;

/**
 * @var array<string,mixed> $d @var list<array<string,mixed>> $messages @var array<string,mixed> $part
 * @var string $refundable @var list<array<string,mixed>> $refunds
 */
$open = in_array($d['status'], DisputeService::OPEN, true);
$who = fn (array $m) => match ($m['author_role']) { 'customer' => 'Customer', 'seller' => $d['seller_name'], default => trim($m['first_name'] . ' ' . $m['last_name']) . ' (' . brand_name() . ')' };
$paid = in_array($part['payment_status'], ['verified', 'cod_collected', 'refund_due', 'refunded'], true);
?>
<div class="wm-admin-head">
    <h1>Case <?= e($d['dispute_number']) ?></h1>
    <span class="badge fs-6 <?= $open ? 'badge-soft-warning' : 'badge-soft-success' ?>"><?= e(brand_text(DisputeService::STATUS_LABELS[$d['status']])) ?></span>
</div>
<p><a href="<?= e(url('/admin/disputes')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Seller cases</a></p>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Messages</div>
            <div class="wm-panel-body">
                <div class="border rounded p-2 mb-2 small"><div class="text-muted"><?= e(ucfirst($d['raised_by_role'])) ?> opened the case · <?= e(date('d M Y H:i', strtotime((string) $d['created_at']))) ?></div><?= nl2br(e((string) $d['details'])) ?></div>
                <?php foreach ($messages as $m): ?>
                    <div class="border rounded p-2 mb-2 small<?= (int) $m['is_internal'] ? ' bg-body-tertiary' : '' ?>">
                        <div class="text-muted"><?= e($who($m)) ?> · <?= e(date('d M Y H:i', strtotime((string) $m['created_at']))) ?><?= (int) $m['is_internal'] ? ' · <strong>internal note</strong>' : '' ?></div><?= nl2br(e((string) $m['body'])) ?></div>
                <?php endforeach; ?>
                <?php if ($open): ?>
                    <form method="post" action="<?= e(url('/admin/disputes/' . $d['id'] . '/message')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <label class="form-label small" for="body">Message</label>
                        <textarea class="form-control<?= invalid('body') ?>" id="body" name="body" rows="3" maxlength="2000"></textarea>
                        <?= field_error('body') ?>
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="internal" name="internal" value="1">
                            <label class="form-check-label small" for="internal">Internal note (staff only — not sent to the customer or the seller)</label></div>
                        <button class="btn btn-wm-green mt-2" type="submit">Send</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Order part</div>
            <dl class="row small wm-panel-body mb-0">
                <dt class="col-5">Order</dt><dd class="col-7"><a href="<?= e(url('/admin/orders/' . $d['order_id'])) ?>"><?= e($d['order_number']) ?></a> · <?= e($d['sub_number']) ?></dd>
                <dt class="col-5">Seller</dt><dd class="col-7"><a href="<?= e(url('/admin/sellers/' . $d['seller_id'])) ?>"><?= e($d['seller_name']) ?></a></dd>
                <dt class="col-5">Customer</dt><dd class="col-7"><?= e($d['customer_name']) ?> · <?= e(Validator::displayPhone((string) $part['customer_phone'])) ?></dd>
                <dt class="col-5">Part status</dt><dd class="col-7"><?= e(OrderPresenter::sellerOrderStatus((string) $part['status'])) ?></dd>
                <dt class="col-5">Paid for part</dt><dd class="col-7"><?= e(money(bcsub((string) $part['items_subtotal'], (string) $part['discount_share'], 2))) ?> (<?= e(str_replace('_', ' ', (string) $part['payment_status'])) ?>)</dd>
                <dt class="col-5">Seller earnings</dt><dd class="col-7"><?= e(['not_accrued' => 'Not earned (unpaid)', 'pending' => 'Pending — held by this case', 'available' => 'Already payable', 'reversed' => 'Reversed'][$part['earnings_status']] ?? '') ?></dd>
                <?php foreach ($refunds as $rf): ?>
                    <dt class="col-5">Refund</dt><dd class="col-7"><?= e(money($rf['amount'])) ?> · <?= e($rf['status']) ?> · <?= e($rf['reason']) ?></dd>
                <?php endforeach; ?>
            </dl>
        </section>
        <?php if ($open): ?>
            <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/disputes/' . $d['id'] . '/status')) ?>">
                <?= csrf_field() ?>
                <div class="wm-panel-head">Waiting for</div>
                <div class="wm-panel-body d-flex gap-2 align-items-end">
                    <div class="flex-grow-1"><label class="form-label small" for="status">Status</label>
                        <select class="form-select form-select-sm" id="status" name="status">
                            <?php foreach (DisputeService::OPEN as $s): ?><option value="<?= e($s) ?>" <?= $d['status'] === $s ? 'selected' : '' ?>><?= e(brand_text(DisputeService::STATUS_LABELS[$s])) ?></option><?php endforeach; ?>
                        </select></div>
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Update</button>
                </div>
            </form>
            <form class="wm-panel" method="post" action="<?= e(url('/admin/disputes/' . $d['id'] . '/resolve')) ?>" data-confirm="Resolve this case? The customer and the seller are told, and any refund is recorded as owed." novalidate>
                <?= csrf_field() ?>
                <div class="wm-panel-head">Resolve</div>
                <div class="wm-panel-body">
                    <label class="form-label small" for="resolution">Decision</label>
                    <select class="form-select form-select-sm<?= invalid('resolution') ?>" id="resolution" name="resolution">
                        <?php foreach (DisputeService::RESOLUTIONS as $k => $l): ?>
                            <?php if (!$paid && str_starts_with($k, 'refund')) continue; ?>
                            <option value="<?= e($k) ?>"><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($paid): ?>
                        <label class="form-label small mt-2" for="refund_amount">Refund amount for a partial refund (KSh)</label>
                        <input class="form-control form-control-sm<?= invalid('refund_amount') ?>" id="refund_amount" name="refund_amount" inputmode="decimal">
                        <div class="form-text">At most <?= e(money($refundable)) ?>. A full refund uses this amount automatically.</div>
                        <?= field_error('refund_amount') ?>
                    <?php else: ?>
                        <p class="small text-muted mt-2 mb-0">Nothing has been paid for this order yet, so refunds are not available.</p>
                    <?php endif; ?>
                    <label class="form-label small mt-2" for="resolution_note">Explanation (the customer and the seller see this)</label>
                    <textarea class="form-control form-control-sm<?= invalid('resolution_note') ?>" id="resolution_note" name="resolution_note" rows="3" maxlength="1000"></textarea>
                    <?= field_error('resolution_note') ?>
                    <button class="btn btn-wm-green w-100 mt-2" type="submit">Resolve case</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
