<?php
use Waimart\Core\Money;
use Waimart\Core\Validator;
use Waimart\Services\OrderPresenter;
use Waimart\Services\PaymentOptions;

/**
 * Shared order detail. $full = owner (or the session that just placed it): full contact details.
 * Otherwise (tracking link / lookup) personal data is masked (D1/D5).
 * @var array<string,mixed> $order
 * @var bool $full
 */
$f = $order['fulfilment'];
$isMpesa = str_starts_with((string) $order['payment_method'], 'mpesa_');
$expires = $order['reservation_expires_at'];
?>
<div class="row g-3">
    <div class="col-lg-8">
        <section class="wm-card p-3 p-md-4 mb-3" aria-labelledby="statusTitle">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 id="statusTitle" class="fs-6 text-uppercase text-muted mb-1">Order <?= e($order['order_number']) ?></h2>
                    <p class="small text-muted mb-0">Placed <?= e(date('j M Y, g:i a', strtotime($order['placed_at']))) ?></p>
                </div>
                <span class="badge fs-6 <?= OrderPresenter::badgeClass($order['status']) ?>"><?= e(OrderPresenter::status($order['status'])) ?></span>
            </div>
            <p class="mt-3 mb-0" role="status"><?= e(OrderPresenter::nextStepText($order, $expires)) ?></p>

            <?php if ($order['status'] === 'ready_for_collection'): ?>
                <div class="wm-collect-box mt-3">
                    <?php if (!empty($collectionCode)): ?>
                        <div class="small text-uppercase fw-bold text-muted">Your collection code</div>
                        <div class="wm-collect-code" aria-label="Collection code <?= e(implode(' ', str_split($collectionCode))) ?>"><?= e(substr($collectionCode, 0, 4) . ' ' . substr($collectionCode, 4)) ?></div>
                        <p class="small mb-0">Show this code and your ID at <strong><?= e($order['fulfilment']['pickup_name']) ?></strong>.
                            Keep it private — Waimart staff will never call you to ask for it.</p>
                    <?php else: ?>
                        <p class="small mb-0"><i class="bi bi-shield-lock" aria-hidden="true"></i> Your collection code was sent to the phone number<?= $order['customer_email'] ? ' and email' : '' ?> used for this order.
                            For your security it is shown here only when you sign in or open your private tracking link.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($isMpesa && $order['status'] === 'awaiting_payment'): ?>
                <div class="wm-pay-box mt-3">
                    <h3 class="fs-6 fw-bold mb-2"><i class="bi bi-phone" aria-hidden="true"></i> How to pay</h3>
                    <dl class="row mb-2 small">
                        <?php foreach (OrderPresenter::mpesaInstructions($order) as $row): ?>
                            <dt class="col-5 col-sm-4 fw-normal text-muted"><?= e($row['label']) ?></dt>
                            <dd class="col-7 col-sm-8 fw-bold mb-1"><?= e($row['value']) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <?php if ($expires): ?>
                        <p class="small mb-1"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Items reserved until <strong><?= e(date('j M, g:i a', strtotime($expires))) ?></strong>.</p>
                    <?php endif; ?>
                    <p class="small text-muted mb-0">Never share your M-Pesa PIN with anyone.</p>
                </div>
            <?php endif; ?>

            <?php if (\Waimart\Services\PaymentService::canSubmit($order)): ?>
                <form class="wm-pay-form mt-3" method="post" action="<?= e(url('/order/' . $order['order_number'] . '/payment')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <h3 class="fs-6 fw-bold mb-1"><?= $order['status'] === 'expired' ? 'Already paid? Submit your M-Pesa code' : 'I have paid — submit my M-Pesa code' ?></h3>
                    <?php if ($order['status'] === 'expired'): ?>
                        <p class="small text-muted">If you paid after the reservation expired, submit your code. Our team will verify it and contact you about your items or a refund.</p>
                    <?php endif; ?>
                    <div class="row g-2">
                        <div class="col-sm-4">
                            <label class="form-label small" for="txcode">M-Pesa transaction code</label>
                            <input class="form-control text-uppercase<?= invalid('transaction_code') ?>" id="txcode" name="transaction_code" maxlength="10" required
                                   autocomplete="off" spellcheck="false" placeholder="e.g. SGH7K2L9XQ" value="<?= e(fv('transaction_code', '')) ?>">
                            <?= field_error('transaction_code') ?>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small" for="payer_phone">Phone number you paid from</label>
                            <input class="form-control<?= invalid('payer_phone') ?>" id="payer_phone" name="payer_phone" type="tel" inputmode="tel" required value="<?= e(fv('payer_phone', '')) ?>">
                            <?= field_error('payer_phone') ?>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small" for="amount">Amount paid (KSh)</label>
                            <input class="form-control<?= invalid('amount') ?>" id="amount" name="amount" inputmode="decimal" required value="<?= e(fv('amount', (string) $order['grand_total'])) ?>">
                            <?= field_error('amount') ?>
                        </div>
                    </div>
                    <button class="btn btn-wm mt-2" type="submit">Submit payment code</button>
                    <p class="small text-muted mt-2 mb-0">Submitting a code does not complete your payment on its own. We confirm your order after verifying it.</p>
                </form>
            <?php endif; ?>

            <?php if (!empty($order['payments'])): ?>
                <h3 class="fs-6 fw-bold mt-4">Payment submissions</h3>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($order['payments'] as $p): ?>
                        <li class="py-1 border-bottom">
                            <code><?= e($p['transaction_code']) ?></code> · <?= e(money($p['amount_claimed'])) ?> ·
                            <span class="badge <?= ['verified' => 'badge-soft-success', 'rejected' => 'badge-soft-danger'][$p['status']] ?? 'badge-soft-warning' ?>">
                                <?= e(['pending' => 'Awaiting verification', 'verified' => 'Verified', 'rejected' => 'Not verified'][$p['status']]) ?></span>
                            <?php if ($p['status'] === 'rejected' && $p['decision_note']): ?><span class="d-block text-muted"><?= e($p['decision_note']) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php $returns = \Waimart\Core\App::db()->all('SELECT return_number, status, refund_amount, decision_note, completion_note, requested_at FROM return_requests WHERE order_id = ? ORDER BY id', [$order['id']]); ?>
            <?php if ((new \Waimart\Services\ReturnService())->eligible($order)): ?>
                <a class="btn btn-sm btn-outline-wm mt-3" href="<?= e(url('/order/' . $order['order_number'] . '/return')) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Request a return</a>
            <?php endif; ?>
            <?php if ($returns): ?>
                <h3 class="fs-6 fw-bold mt-4">Returns</h3>
                <?php foreach ($returns as $rt): ?>
                    <p class="small mb-1"><strong><?= e($rt['return_number']) ?></strong> ·
                        <?= e(['requested' => 'Under review', 'approved' => 'Approved — please return the items', 'rejected' => 'Not accepted', 'received' => 'Received — being inspected', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$rt['status']] ?? $rt['status']) ?>
                        <?= $rt['status'] === 'completed' && $rt['refund_amount'] !== null ? ' · Refund ' . e(money($rt['refund_amount'])) : '' ?>
                        <?= $rt['status'] === 'rejected' && $rt['decision_note'] ? '<span class="d-block text-muted">' . e($rt['decision_note']) . '</span>' : '' ?></p>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (\Waimart\Services\OrderWorkflow::customerCanCancel($order)): ?>
                <details class="mt-3">
                    <summary class="small text-danger">Cancel this order</summary>
                    <form method="post" action="<?= e(url('/order/' . $order['order_number'] . '/cancel')) ?>" class="mt-2" data-confirm="Cancel this order? Your reserved items will be released.">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="cancelReason">Reason</label>
                        <select class="form-select form-select-sm mb-2" id="cancelReason" name="reason" required>
                            <option value="">Choose a reason…</option>
                            <option value="changed_mind">Changed my mind</option>
                            <option value="ordered_by_mistake">Ordered by mistake</option>
                            <option value="found_cheaper">Found it cheaper elsewhere</option>
                            <option value="delivery_too_long">Delivery takes too long</option>
                            <option value="other">Other</option>
                        </select>
                        <button class="btn btn-sm btn-outline-danger" type="submit">Cancel order</button>
                    </form>
                </details>
            <?php endif; ?>

            <?php if ($order['history']): ?>
                <h3 class="fs-6 fw-bold mt-4">Order history</h3>
                <ol class="wm-timeline">
                    <?php foreach ($order['history'] as $h): ?>
                        <li><strong><?= e(OrderPresenter::status($h['to_status'])) ?></strong>
                            <span class="text-muted small d-block"><?= e(date('j M Y, g:i a', strtotime($h['created_at']))) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <?php
        $groups = $order['groups'] ?? [];
        $marketplace = count($groups) > 1 || (count($groups) === 1 && (int) $groups[0]['seller_is_platform'] === 0);
        ?>
        <section class="wm-card mb-3" aria-labelledby="itemsTitle">
            <h2 id="itemsTitle" class="fs-6 fw-bold text-uppercase px-3 pt-3 pb-2 mb-0 border-bottom">Items (<?= array_sum(array_column($order['items'], 'quantity')) ?>)</h2>
            <?php if ($marketplace): ?>
                <?php if (count($groups) > 1): ?>
                    <p class="small text-muted px-3 pt-2 mb-0">This order includes items from <?= count($groups) ?> sellers. Each seller prepares their items separately, so parts of your order may arrive or be ready at different times.</p>
                <?php endif; ?>
                <?php foreach ($groups as $g): ?>
                    <div class="px-3 pt-3 pb-1 d-flex flex-wrap justify-content-between gap-2 border-top mt-2">
                        <div class="small"><strong>Sold by <a href="<?= e(url('/store/' . $g['seller_slug'])) ?>"><?= e($g['seller_name']) ?></a></strong>
                            <span class="text-muted">· <?= e($g['sub_number']) ?></span></div>
                        <span class="badge badge-soft-<?= in_array($g['status'], ['cancelled', 'rejected'], true) ? 'danger' : (in_array($g['status'], ['delivered', 'collected'], true) ? 'success' : 'muted') ?>"><?= e(\Waimart\Services\OrderPresenter::sellerOrderStatus((string) $g['status'])) ?></span>
                    </div>
                    <?php foreach ($g['items'] as $it): ?>
                        <div class="wm-cart-line">
                            <span class="wm-cart-img"><img src="<?= e(media($it['image_path'])) ?>" alt="" width="80" height="80" loading="lazy"></span>
                            <div>
                                <div class="fw-semibold small"><?= e($it['product_name']) ?></div>
                                <?php if ($it['variant_name'] !== 'Default'): ?><div class="small text-muted"><?= e($it['variant_name']) ?></div><?php endif; ?>
                                <div class="small text-muted">Qty <?= (int) $it['quantity'] ?> × <?= e(money($it['unit_price'])) ?></div>
                            </div>
                            <div class="text-end fw-bold small"><?= e(money($it['line_total'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($order['items'] as $it): ?>
                <div class="wm-cart-line">
                    <span class="wm-cart-img"><img src="<?= e(media($it['image_path'])) ?>" alt="" width="80" height="80" loading="lazy"></span>
                    <div>
                        <div class="fw-semibold small"><?= e($it['product_name']) ?></div>
                        <?php if ($it['variant_name'] !== 'Default'): ?><div class="small text-muted"><?= e($it['variant_name']) ?></div><?php endif; ?>
                        <div class="small text-muted">Qty <?= (int) $it['quantity'] ?> × <?= e(money($it['unit_price'])) ?></div>
                    </div>
                    <div class="text-end fw-bold small"><?= e(money($it['line_total'])) ?></div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

    <aside class="col-lg-4">
        <div class="wm-card p-3 mb-3">
            <h2 class="fs-6 fw-bold text-uppercase border-bottom pb-2">Summary</h2>
            <div class="d-flex justify-content-between small"><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
            <?php if (!Money::isZero((string) $order['discount_total'])): ?>
                <div class="d-flex justify-content-between small text-success"><span>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><span>−<?= e(money($order['discount_total'])) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between small"><span><?= $order['fulfilment_method'] === 'pickup' ? 'Pickup' : 'Delivery' ?></span><span><?= Money::isZero((string) $order['delivery_fee']) ? 'Free' : e(money($order['delivery_fee'])) ?></span></div>
            <div class="d-flex justify-content-between fw-bold fs-5 border-top pt-2 mt-2"><span>Total</span><span><?= e(money($order['grand_total'])) ?></span></div>
            <div class="small mt-2"><span class="text-muted">Payment:</span> <?= e(PaymentOptions::LABELS[$order['payment_method']] ?? $order['payment_method']) ?></div>
            <div class="small"><span class="text-muted">Payment status:</span> <?= e(OrderPresenter::payment($order['payment_status'])) ?></div>
        </div>

        <div class="wm-card p-3">
            <?php if ($order['fulfilment_method'] === 'pickup'): ?>
                <h2 class="fs-6 fw-bold text-uppercase border-bottom pb-2">Pickup station</h2>
                <p class="small mb-1 fw-semibold"><?= e($f['pickup_name']) ?></p>
                <p class="small mb-1"><?= e($f['pickup_address']) ?>, <?= e($f['pickup_town']) ?>, <?= e($f['pickup_county']) ?></p>
                <p class="small mb-1 text-muted"><i class="bi bi-clock" aria-hidden="true"></i> <?= e($f['pickup_hours']) ?></p>
                <?php if ($f['pickup_instructions']): ?><p class="small mb-1 text-muted"><?= e($f['pickup_instructions']) ?></p><?php endif; ?>
                <?php if ($f['pickup_map_url']): ?><a class="small" href="<?= e($f['pickup_map_url']) ?>" target="_blank" rel="noopener noreferrer">View on map</a><?php endif; ?>
                <p class="small mt-2 mb-0 text-muted">Please wait until we tell you your order is <strong>ready for collection</strong> before visiting the station.</p>
            <?php else: ?>
                <h2 class="fs-6 fw-bold text-uppercase border-bottom pb-2">Delivery</h2>
                <?php if ($full): ?>
                    <p class="small mb-1"><?= e($f['location']) ?></p>
                <?php endif; ?>
                <p class="small mb-1"><?= e($f['town']) ?>, <?= e($f['county_name']) ?></p>
                <?php if ($f['eta_min_days'] !== null): ?>
                    <p class="small text-muted mb-0">Estimated <?= (int) $f['eta_min_days'] ?>–<?= (int) $f['eta_max_days'] ?> business days after dispatch (estimate).</p>
                <?php endif; ?>
            <?php endif; ?>
            <h3 class="fs-6 fw-bold mt-3 mb-1">Customer</h3>
            <?php if ($full): ?>
                <p class="small mb-0"><?= e($order['customer_name']) ?><br><?= e(Validator::displayPhone((string) $order['customer_phone'])) ?><?= $order['customer_email'] ? '<br>' . e($order['customer_email']) : '' ?></p>
            <?php else: ?>
                <p class="small mb-0"><?= e(OrderPresenter::firstName((string) $order['customer_name'])) ?> · <?= e(OrderPresenter::maskPhone((string) $order['customer_phone'])) ?></p>
            <?php endif; ?>
        </div>
    </aside>
</div>
