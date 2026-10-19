<?php
use Waimart\Core\Money;
use Waimart\Core\Validator;
use Waimart\Services\OrderPresenter;
use Waimart\Services\PaymentOptions;

/**
 * @var array<string,mixed> $order
 * @var list<array<string,mixed>> $reservations
 * @var list<array<string,mixed>> $movements
 * @var list<string> $actions
 */
$f = $order['fulfilment'];
$labels = ['confirmed' => 'Confirm order', 'processing' => 'Start processing', 'packed' => 'Mark packed', 'dispatched' => 'Dispatch (hand over to rider)',
           'out_for_delivery' => 'Out for delivery', 'delivered' => 'Mark delivered', 'cancelled' => 'Cancel order',
           'ready_for_collection' => 'Mark ready for collection'];
?>
<div class="wm-admin-head">
    <div>
        <h1><?= e($order['order_number']) ?> <span class="badge fs-6 align-middle <?= OrderPresenter::badgeClass($order['status']) ?>"><?= e(OrderPresenter::status($order['status'])) ?></span></h1>
        <div class="small text-muted">Placed <?= e(date('d M Y H:i', strtotime($order['placed_at']))) ?> · <?= $order['user_id'] ? 'Registered customer' : 'Guest' ?></div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/orders')) ?>">All orders</a>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <?php if ($actions || $order['status'] === 'ready_for_collection'): ?>
            <section class="wm-panel mb-3" aria-labelledby="actTitle">
                <div class="wm-panel-head"><span id="actTitle">Next steps</span></div>
                <div class="wm-panel-body d-flex flex-wrap gap-2 align-items-start">
                    <?php foreach (array_diff($actions, ['cancelled']) as $to): ?>
                        <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/status')) ?>" class="d-flex gap-2 align-items-start"
                              data-confirm="<?= e($labels[$to] . '?' . (in_array($to, ['dispatched'], true) ? ' Stock will be deducted from inventory.' : '')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="to" value="<?= e($to) ?>">
                            <?php if ($to === 'delivered'): ?>
                                <label class="visually-hidden" for="conf">Delivery confirmation</label>
                                <input class="form-control form-control-sm" id="conf" name="confirmation" placeholder="e.g. Received by Jane (ID seen)" maxlength="255">
                            <?php endif; ?>
                            <button class="btn btn-sm btn-wm-green text-nowrap" type="submit"><?= e($labels[$to]) ?></button>
                        </form>
                    <?php endforeach; ?>
                    <?php if (in_array('cancelled', $actions, true)): ?>
                        <details class="ms-auto">
                            <summary class="btn btn-sm btn-outline-danger">Cancel order…</summary>
                            <form method="post" action="<?= e(url('/admin/orders/' . $order['id'] . '/status')) ?>" class="mt-2" style="min-width:280px" data-confirm="Cancel this order? Reserved stock will be released.">
                                <?= csrf_field() ?><input type="hidden" name="to" value="cancelled">
                                <label class="form-label small" for="reason">Reason (shown to customer)</label>
                                <input class="form-control form-control-sm<?= invalid('reason') ?>" id="reason" name="reason" required maxlength="200">
                                <?= field_error('reason') ?>
                                <button class="btn btn-sm btn-danger mt-2" type="submit">Confirm cancellation</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
                <?php if ($order['status'] === 'ready_for_collection'): ?>
                    <p class="small px-3 pb-3 mb-0">
                        <?php if (\Waimart\Core\Auth::can('orders.collect')): ?>
                            <a class="btn btn-sm btn-outline-wm" href="<?= e(url('/admin/collections/' . $order['id'])) ?>"><i class="bi bi-upc-scan" aria-hidden="true"></i> Hand over at collection desk</a>
                        <?php endif; ?>
                        <span class="text-muted d-block mt-1">Collection requires the customer's code and an ID check.</span>
                    </p>
                <?php endif; ?>
                <?php if (in_array($order['status'], ['awaiting_payment', 'awaiting_verification'], true)): ?>
                    <p class="small text-muted px-3 pb-3 mb-0">This M-Pesa order is confirmed when its payment is verified.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Items</div>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>SKU</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Fulfilled</th><th class="text-end">Unit</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                <?php foreach ($order['items'] as $it): ?>
                    <tr><td><code><?= e($it['sku']) ?></code></td><td><?= e($it['product_name']) ?><?= $it['variant_name'] !== 'Default' ? ' · ' . e($it['variant_name']) : '' ?></td>
                        <td class="text-end"><?= (int) $it['quantity'] ?></td><td class="text-end"><?= (int) $it['fulfilled_qty'] ?></td>
                        <td class="text-end"><?= e(money($it['unit_price'])) ?></td><td class="text-end"><?= e(money($it['line_total'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <section class="wm-panel mb-3" aria-labelledby="soTitle">
            <div class="wm-panel-head"><span id="soTitle">Seller sub-orders</span><span class="small text-muted fw-normal"><?= count($order['groups'] ?? []) ?></span></div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Sub-order</th><th>Seller</th><th>Status</th><th class="text-end">Merchandise</th><th class="text-end">Discount share</th>
                    <th class="text-end">Commission</th><th class="text-end">Seller net</th></tr></thead>
                <tbody>
                <?php foreach ($order['groups'] ?? [] as $g): $rates = array_unique(array_filter(array_column($g['items'], 'commission_rate'), fn ($r) => $r !== null)); ?>
                    <tr>
                        <td class="text-nowrap"><code><?= e($g['sub_number']) ?></code><?= (int) $g['is_legacy'] ? ' <span class="badge badge-soft-muted">Pre-marketplace</span>' : '' ?></td>
                        <td><a href="<?= e(url('/admin/sellers/' . $g['seller_id'])) ?>"><?= e($g['seller_name']) ?></a></td>
                        <td><?= e(\Waimart\Services\OrderPresenter::sellerOrderStatus((string) $g['status'])) ?></td>
                        <td class="text-end"><?= e(money($g['items_subtotal'])) ?></td>
                        <td class="text-end"><?= e(money($g['discount_share'])) ?></td>
                        <td class="text-end"><?= e(money($g['commission_total'])) ?><?= $rates ? '<div class="small text-muted">' . e(implode(', ', array_map(fn ($r) => rtrim(rtrim((string) $r, '0'), '.') . '%', $rates))) . '</div>' : '' ?></td>
                        <td class="text-end"><?= e(money($g['seller_net'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (!empty($marketplace)): ?>
                <div class="wm-panel-body border-top">
                    <p class="small mb-2"><strong>Fulfilment by part.</strong> Sellers accept, prepare and pack their items; <?= e(brand_name()) ?> confirms receipt (hand-over), then dispatches and delivers, or makes them ready at the pickup station. The order status follows the least advanced part.</p>
                    <?php $labels = ['accepted' => 'Accept (Official Store)', 'processing' => 'Start preparing', 'packed' => 'Mark packed', 'handed_over' => 'Received from seller',
                        'dispatched' => 'Dispatch', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Mark delivered', 'ready_for_collection' => 'Ready at pickup station']; ?>
                    <?php foreach ($order['groups'] as $g): $acts = $partActions[(int) $g['id']] ?? []; if (!$acts) { continue; } ?>
                        <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
                            <span class="small fw-semibold me-1"><?= e($g['sub_number']) ?> · <?= e($g['seller_name']) ?>:</span>
                            <?php foreach ($acts as $to): if ($to === 'cancelled') { continue; } ?>
                                <form class="d-flex align-items-end gap-1" method="post" action="<?= e(url('/admin/seller-orders/' . $g['id'] . '/status')) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="to" value="<?= e($to) ?>">
                                    <?php if ($to === 'dispatched'): ?>
                                        <label class="visually-hidden" for="tr<?= (int) $g['id'] ?>">Delivery reference for <?= e($g['sub_number']) ?></label>
                                        <input class="form-control form-control-sm" id="tr<?= (int) $g['id'] ?>" name="tracking_reference" maxlength="80" placeholder="Delivery reference (optional)" style="width:200px">
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-wm-green" type="submit"><?= e($labels[$to] ?? $to) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <?php if (in_array('cancelled', $acts, true)): ?>
                                <details><summary class="small text-danger">Cancel this part…</summary>
                                    <form class="d-flex align-items-end gap-1 mt-1" method="post" action="<?= e(url('/admin/seller-orders/' . $g['id'] . '/status')) ?>" data-confirm="Cancel this seller's part? Its items are released and, if paid, a refund is recorded." novalidate>
                                        <?= csrf_field() ?><input type="hidden" name="to" value="cancelled">
                                        <label class="visually-hidden" for="cr<?= (int) $g['id'] ?>">Reason for cancelling <?= e($g['sub_number']) ?></label>
                                        <input class="form-control form-control-sm" id="cr<?= (int) $g['id'] ?>" name="reason" maxlength="500" placeholder="Reason (customer sees it)" style="width:240px">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Cancel part</button>
                                    </form></details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="wm-panel-body small text-muted pt-2">Commission is fixed at the rate in force when the order was placed. Delivery fees belong to <?= e(brand_name()) ?>. Coupon discounts are funded by <?= e(brand_name()) ?> and do not reduce seller net.</div>
        </section>

        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Stock reservations</div>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>#</th><th>SKU</th><th class="text-end">Qty</th><th>Status</th><th>Created</th><th>Expires</th><th>Released / consumed</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($reservations as $r): ?>
                    <tr><td><?= (int) $r['id'] ?></td><td><code><?= e($r['sku']) ?></code></td><td class="text-end"><?= (int) $r['quantity'] ?></td>
                        <td><span class="badge <?= ['active' => 'badge-soft-warning', 'consumed' => 'badge-soft-success'][$r['status']] ?? 'badge-soft-muted' ?>"><?= e($r['status']) ?></span></td>
                        <td class="small"><?= e(date('d M H:i', strtotime($r['created_at']))) ?></td>
                        <td class="small"><?= $r['expires_at'] ? e(date('d M H:i', strtotime($r['expires_at']))) : ($r['status'] === 'active' ? 'Held until fulfilment' : '—') ?></td>
                        <td class="small"><?= e($r['consumed_at'] ?? $r['released_at'] ?? '') ?></td><td class="small"><?= e($r['release_reason'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Stock ledger for this order</div>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>When</th><th>SKU</th><th>Type</th><th class="text-end">On hand Δ</th><th class="text-end">Reserved Δ</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr><td class="small"><?= e(date('d M H:i:s', strtotime($m['created_at']))) ?></td><td><code><?= e($m['sku']) ?></code></td><td><?= e($m['movement_type']) ?></td>
                        <td class="text-end"><?= sprintf('%+d', $m['on_hand_delta']) ?></td><td class="text-end"><?= sprintf('%+d', $m['reserved_delta']) ?></td><td class="small"><?= e(ucfirst($m['actor_type'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <section class="wm-panel">
            <div class="wm-panel-head">Status history</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($order['history'] as $h): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span><strong><?= e(OrderPresenter::status($h['to_status'])) ?></strong><?= $h['note'] ? ' — ' . e($h['note']) : '' ?></span>
                        <span class="text-muted text-nowrap"><?= e(date('d M H:i', strtotime($h['created_at']))) ?> · <?= e($h['first_name'] ? $h['first_name'] . ' ' . $h['last_name'] : ucfirst($h['actor_type'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="wm-panel mb-3 wm-panel-body small">
            <h2 class="fs-6 fw-bold">Customer</h2>
            <p class="mb-1"><?= e($order['customer_name']) ?></p>
            <p class="mb-1"><?= e(Validator::displayPhone((string) $order['customer_phone'])) ?></p>
            <?php if ($order['customer_email']): ?><p class="mb-0"><?= e($order['customer_email']) ?></p><?php endif; ?>
            <?php if ($order['customer_notes']): ?><p class="mt-2 mb-0"><strong>Notes:</strong> <?= e($order['customer_notes']) ?></p><?php endif; ?>
        </section>
        <?php if ((int) $order['requires_resolution'] === 1): ?>
            <div class="alert alert-warning small">This order needs resolution. <a href="<?= e(url('/admin/resolutions')) ?>">Open the resolution queue</a>.</div>
        <?php endif; ?>
        <?php if (!empty($order['payments'])): ?>
            <section class="wm-panel mb-3 wm-panel-body small">
                <h2 class="fs-6 fw-bold">M-Pesa submissions</h2>
                <?php foreach ($order['payments'] as $p): ?>
                    <div class="border-bottom py-1">
                        <code><?= e($p['transaction_code']) ?></code> · <?= e(money($p['amount_claimed'])) ?> ·
                        <span class="badge <?= ['verified' => 'badge-soft-success', 'rejected' => 'badge-soft-danger'][$p['status']] ?? 'badge-soft-warning' ?>"><?= e($p['status']) ?></span>
                        <?php if ($p['decision_note']): ?><div class="text-muted"><?= e($p['decision_note']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (array_filter($order['payments'], fn ($p) => $p['status'] === 'pending') && \Waimart\Core\Auth::can('payments.verify')): ?>
                    <a class="btn btn-sm btn-outline-wm mt-2" href="<?= e(url('/admin/payments')) ?>">Verify in payments queue</a>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <?php $cod = $order['payment_method'] === 'cod' ? \Waimart\Core\App::db()->one('SELECT c.*, u.first_name FROM cod_collections c JOIN users u ON u.id = c.collected_by WHERE c.order_id = ?', [$order['id']]) : null; ?>
        <?php if ($cod): ?>
            <section class="wm-panel mb-3 wm-panel-body small">
                <h2 class="fs-6 fw-bold">Cash collected</h2>
                <p class="mb-0"><?= e(money($cod['amount_collected'])) ?> of <?= e(money($cod['amount_expected'])) ?> by <?= e($cod['first_name']) ?>, <?= e(date('d M Y H:i', strtotime($cod['collected_at']))) ?></p>
                <?php if ($cod['note']): ?><p class="mb-0 text-muted"><?= e($cod['note']) ?></p><?php endif; ?>
            </section>
        <?php elseif ($order['payment_method'] === 'cod' && in_array($order['status'], \Waimart\Services\CodService::COLLECTABLE, true) && \Waimart\Core\Auth::can('cod.collect')): ?>
            <div class="alert alert-info small">Cash not yet recorded. <a href="<?= e(url('/admin/cod')) ?>">Record COD cash</a>.</div>
        <?php endif; ?>
        <section class="wm-panel mb-3 wm-panel-body small">
            <h2 class="fs-6 fw-bold">Payment</h2>
            <p class="mb-1"><?= e(PaymentOptions::label((string) $order['payment_method'])) ?></p>
            <p class="mb-2"><?= e(OrderPresenter::payment($order['payment_status'])) ?></p>
            <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
            <?php if (!Money::isZero((string) $order['discount_total'])): ?><div class="d-flex justify-content-between"><span>Discount <?= e($order['coupon_code'] ?? '') ?></span><span>−<?= e(money($order['discount_total'])) ?></span></div><?php endif; ?>
            <div class="d-flex justify-content-between"><span>Delivery / pickup</span><span><?= e(money($order['delivery_fee'])) ?></span></div>
            <div class="d-flex justify-content-between fw-bold border-top mt-1 pt-1"><span>Total</span><span><?= e(money($order['grand_total'])) ?></span></div>
        </section>
        <section class="wm-panel wm-panel-body small">
            <h2 class="fs-6 fw-bold"><?= $order['fulfilment_method'] === 'pickup' ? 'Pickup' : 'Delivery' ?></h2>
            <?php if ($order['fulfilment_method'] === 'pickup'): ?>
                <p class="mb-1"><?= e($f['pickup_name']) ?> (<?= e($f['pickup_code']) ?>)</p>
                <p class="mb-1"><?= e($f['pickup_address']) ?>, <?= e($f['pickup_town']) ?></p>
            <?php else: ?>
                <p class="mb-1"><?= e($f['location']) ?>, <?= e($f['town']) ?>, <?= e($f['county_name']) ?></p>
                <?php if ($f['delivery_instructions']): ?><p class="mb-1 text-muted"><?= e($f['delivery_instructions']) ?></p><?php endif; ?>
                <p class="mb-1">Zone: <?= e($f['zone_name']) ?> (<?= (int) $f['eta_min_days'] ?>–<?= (int) $f['eta_max_days'] ?> days)</p>
            <?php endif; ?>
            <?php foreach (['dispatched_at' => 'Dispatched', 'delivered_at' => 'Delivered', 'collected_at' => 'Collected'] as $col => $lbl): ?>
                <?php if (!empty($f[$col])): ?><p class="mb-0"><?= $lbl ?>: <?= e(date('d M Y H:i', strtotime($f[$col]))) ?></p><?php endif; ?>
            <?php endforeach; ?>
            <?php if (!empty($f['delivery_confirmation'])): ?><p class="mb-0">Confirmation: <?= e($f['delivery_confirmation']) ?></p><?php endif; ?>
        </section>
    </div>
</div>
