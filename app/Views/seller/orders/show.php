<?php
use Waimart\Services\OrderPresenter;
use Waimart\Services\SellerContext;
use Waimart\Services\SellerProductService;

/** @var array<string,mixed> $so @var list<string> $next @var array<string,mixed> $seller @var list<array<string,mixed>> $returns @var list<array<string,mixed>> $cases */
$canCommit = in_array(SellerContext::role(), ['owner', 'manager'], true);
$active = $seller['status'] === 'active';
?>
<div class="wm-admin-head">
    <h1>Order <?= e($so['sub_number']) ?></h1>
    <span class="badge fs-6 <?= in_array($so['status'], ['cancelled', 'rejected'], true) ? 'badge-soft-danger' : 'badge-soft-muted' ?>"><?= e(OrderPresenter::sellerOrderStatus((string) $so['status'])) ?></span>
</div>
<p><a href="<?= e(url('/seller/orders')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> All orders</a></p>

<div class="row g-3">
    <div class="col-lg-8">
        <?php if ($active && $next): ?>
            <section class="wm-panel mb-3">
                <div class="wm-panel-head">Next step</div>
                <div class="wm-panel-body d-flex flex-wrap gap-3 align-items-start">
                    <?php if (in_array('accepted', $next, true) && $canCommit): ?>
                        <form method="post" action="<?= e(url('/seller/orders/' . $so['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="accepted">
                            <button class="btn btn-wm-green" type="submit">Accept order</button></form>
                    <?php endif; ?>
                    <?php if (in_array('processing', $next, true)): ?>
                        <form method="post" action="<?= e(url('/seller/orders/' . $so['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="processing">
                            <button class="btn btn-wm-green" type="submit">Start preparing</button></form>
                    <?php endif; ?>
                    <?php if (in_array('packed', $next, true)): ?>
                        <form class="d-flex flex-wrap gap-2 align-items-end" method="post" action="<?= e(url('/seller/orders/' . $so['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="to" value="packed">
                            <div><label class="form-label small mb-1" for="note">Note for <?= e(brand_name()) ?> <span class="text-muted">(optional, e.g. number of parcels)</span></label>
                                <input class="form-control form-control-sm" id="note" name="note" maxlength="500"></div>
                            <button class="btn btn-wm-green" type="submit">Mark packed — ready to hand over</button></form>
                    <?php endif; ?>
                    <?php if (in_array('rejected', $next, true) && $canCommit): ?>
                        <details class="w-100">
                            <summary class="small text-danger">Cannot supply this order?</summary>
                            <form class="mt-2" method="post" action="<?= e(url('/seller/orders/' . $so['id'] . '/status')) ?>" data-confirm="Decline this order? The customer will be told and your items released." novalidate>
                                <?= csrf_field() ?><input type="hidden" name="to" value="rejected">
                                <label class="form-label small" for="reason">Reason (shown to the customer)</label>
                                <input class="form-control form-control-sm<?= invalid('reason') ?>" id="reason" name="reason" maxlength="500" placeholder="e.g. Item damaged in storage">
                                <?= field_error('reason') ?>
                                <button class="btn btn-sm btn-outline-danger mt-2" type="submit">Decline order</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($so['status'] === 'packed'): ?>
            <div class="alert alert-info">Packed and waiting for <?= e(brand_name()) ?> to collect it from you. We will update this order when we receive the parcel.</div>
        <?php endif; ?>

        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Items</div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th><th class="text-end">Commission</th><th class="text-end">You earn</th></tr></thead>
                <tbody>
                <?php foreach ($so['items'] as $it): ?>
                    <tr>
                        <td><?= e($it['product_name']) ?><?= $it['variant_name'] !== 'Default' ? '<div class="small text-muted">' . e($it['variant_name']) . '</div>' : '' ?></td>
                        <td><code><?= e(SellerProductService::displaySku((string) $it['sku'])) ?></code></td>
                        <td class="text-end"><?= (int) $it['quantity'] ?></td>
                        <td class="text-end text-nowrap"><?= e(money($it['unit_price'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(money($it['line_total'])) ?></td>
                        <td class="text-end text-nowrap"><?= $it['commission_amount'] !== null ? e(money($it['commission_amount'])) . ' <span class="small text-muted">(' . e(rtrim(rtrim((string) $it['commission_rate'], '0'), '.')) . '%)</span>' : '—' ?></td>
                        <td class="text-end text-nowrap fw-semibold"><?= $it['seller_net_amount'] !== null ? e(money($it['seller_net_amount'])) : e(money($it['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="4">Total</th><th class="text-end"><?= e(money($so['items_subtotal'])) ?></th><th class="text-end"><?= e(money($so['commission_total'])) ?></th><th class="text-end"><?= e(money($so['seller_net'])) ?></th></tr></tfoot>
            </table></div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Fulfilment</div>
            <div class="wm-panel-body small">
                <p class="mb-1"><strong><?= $so['fulfilment_method'] === 'pickup' ? 'Pickup station' : 'Home delivery by ' . brand_name() ?></strong></p>
                <p class="mb-1"><?= $so['fulfilment_method'] === 'pickup' ? e((string) $so['pickup_name']) : e(trim($so['delivery_town'] . ', ' . $so['delivery_county'], ', ')) ?></p>
                <p class="mb-1">Customer: <?= e((string) $so['customer_first_name']) ?></p>
                <p class="text-muted mb-0"><?= e(brand_name()) ?> delivers to the customer, so you do not need their contact details. Pack the items with this order number and hand them to <?= e(brand_name()) ?>.</p>
                <?php if ($so['tracking_reference']): ?><p class="mt-2 mb-0">Delivery reference: <strong><?= e($so['tracking_reference']) ?></strong></p><?php endif; ?>
                <?php if ($so['reject_reason'] || $so['cancel_reason']): ?><p class="mt-2 mb-0 text-danger"><?= e((string) ($so['reject_reason'] ?: $so['cancel_reason'])) ?></p><?php endif; ?>
            </div>
        </section>
        <?php if ($returns || $cases): ?>
            <section class="wm-panel mb-3">
                <div class="wm-panel-head">Returns &amp; cases</div>
                <ul class="list-unstyled small wm-panel-body mb-0">
                    <?php foreach ($returns as $rt): ?>
                        <li class="mb-1">Return <?= e($rt['return_number']) ?> · <?= e(ucfirst($rt['status'])) ?><?= $rt['refund_amount'] !== null ? ' · refund ' . e(money($rt['refund_amount'])) : '' ?></li>
                    <?php endforeach; ?>
                    <?php foreach ($cases as $c): ?>
                        <li class="mb-1"><a href="<?= e(url('/seller/disputes/' . $c['id'])) ?>">Case <?= e($c['dispute_number']) ?></a> · <?= e(brand_text(\Waimart\Services\DisputeService::STATUS_LABELS[$c['status']])) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <section class="wm-panel">
            <div class="wm-panel-head">History</div>
            <ol class="list-unstyled small wm-panel-body mb-0">
                <?php foreach ($so['history'] as $h): ?>
                    <li class="mb-2"><span class="text-muted"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></span><br>
                        <?= e(OrderPresenter::sellerOrderStatus((string) $h['to_status'])) ?> <span class="text-muted">· <?= e(['seller' => 'you', 'staff' => brand_name(), 'system' => 'automatic', 'customer' => 'customer'][$h['actor_type']] ?? $h['actor_type']) ?></span>
                        <?php if ($h['note']): ?><div class="text-muted"><?= e($h['note']) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
</div>
