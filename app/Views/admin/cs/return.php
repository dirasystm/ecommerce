<?php
use Waimart\Core\Validator;

/** @var array<string,mixed> $r @var list<array<string,mixed>> $items @var array<string,string> $reasons @var string $maxRefund */
$pending = array_filter($items, fn ($i) => $i['inspection'] === 'pending');
$suggested = '0.00';
foreach ($items as $i) {
    $suggested = \Waimart\Core\Money::add($suggested, \Waimart\Core\Money::mul((string) $i['unit_price'], (int) $i['quantity']));
}
$form = fn (string $action, string $label, string $class, string $confirm, string $extra = '') =>
    '<form method="post" action="' . e(url('/admin/returns/' . $r['id'])) . '" class="d-inline" data-confirm="' . e($confirm) . '">' . csrf_field()
    . '<input type="hidden" name="action" value="' . $action . '">' . $extra . '<button class="btn btn-sm ' . $class . '" type="submit">' . e($label) . '</button></form>';
?>
<div class="wm-admin-head">
    <div>
        <h1><?= e($r['return_number']) ?> <span class="badge fs-6 align-middle badge-soft-warning"><?= e($r['status']) ?></span></h1>
        <div class="small text-muted">Order <a href="<?= e(url('/admin/orders/' . $r['order_id'])) ?>"><?= e($r['order_number']) ?></a> · <?= e($r['customer_name']) ?> · <?= e(Validator::displayPhone((string) $r['customer_phone'])) ?></div>
<?php if ($r['sub_number']): ?><div class="small text-muted">Seller: <strong><?= e($r['seller_name']) ?></strong> (part <?= e($r['sub_number']) ?>)<?php if (!(int) $r['is_platform'] && in_array($r['earnings_status'], ['pending', 'available'], true)): ?> — a refund here is deducted from this seller's earnings in proportion (<?= e(brand_name()) ?> returns its commission share)<?php endif; ?></div><?php endif; ?>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/returns')) ?>">All returns</a>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <section class="wm-panel mb-3 wm-panel-body small">
            <p class="mb-1"><strong>Reason:</strong> <?= e($reasons[$r['reason']] ?? $r['reason']) ?></p>
            <?php if ($r['details']): ?><p class="mb-1"><strong>Customer says:</strong> <?= nl2br(e($r['details'])) ?></p><?php endif; ?>
            <?php if ($r['decision_note']): ?><p class="mb-1"><strong>Decision note:</strong> <?= e($r['decision_note']) ?></p><?php endif; ?>
            <?php if ($r['status'] === 'completed'): ?><p class="mb-0"><strong>Refund:</strong> <?= e(money((string) $r['refund_amount'])) ?><?= $r['completion_note'] ? ' — ' . e($r['completion_note']) : '' ?></p><?php endif; ?>
        </section>

        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Items</div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                <thead><tr><th scope="col">Item</th><th scope="col" class="text-end">Qty</th><th scope="col" class="text-end">Value</th><th scope="col">Inspection</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td><?= e($i['product_name']) ?><?= $i['variant_name'] !== 'Default' ? ' · ' . e($i['variant_name']) : '' ?> <code class="small"><?= e($i['sku']) ?></code></td>
                        <td class="text-end"><?= (int) $i['quantity'] ?></td>
                        <td class="text-end"><?= e(money(\Waimart\Core\Money::mul((string) $i['unit_price'], (int) $i['quantity']))) ?></td>
                        <td>
                            <?php if ($i['inspection'] !== 'pending'): ?>
                                <span class="badge <?= $i['inspection'] === 'restock' ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= $i['inspection'] === 'restock' ? 'Passed — restocked' : 'Failed — written off' ?></span>
                                <span class="small text-muted"><?= e((string) $i['inspector']) ?><?= $i['note'] ? ': ' . e($i['note']) : '' ?></span>
                            <?php elseif ($r['status'] === 'received'): ?>
                                <form method="post" action="<?= e(url('/admin/returns/' . $r['id'])) ?>" class="d-flex flex-wrap gap-1">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="inspect"><input type="hidden" name="return_item_id" value="<?= (int) $i['id'] ?>">
                                    <input class="form-control form-control-sm" name="note" maxlength="300" placeholder="Condition note" aria-label="Condition note" style="max-width:200px">
                                    <button class="btn btn-sm btn-outline-success" name="outcome" value="restock" type="submit" title="Resaleable: add back to stock">Pass &amp; restock</button>
                                    <button class="btn btn-sm btn-outline-danger" name="outcome" value="write_off" type="submit" title="Not resaleable: record, no stock added">Fail &amp; write off</button>
                                </form>
                            <?php else: ?>
                                <span class="small text-muted">Pending (inspect after receiving)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="small text-muted px-3 py-2 mb-0">Stock is never restored automatically: only items that pass inspection are added back to inventory.</p>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="wm-panel wm-panel-body">
            <h2 class="fs-6 fw-bold">Next step</h2>
            <?php if ($r['status'] === 'requested'): ?>
                <?= $form('approve', 'Approve return', 'btn-wm-green', 'Approve this return? The customer will be told to bring/send the items.') ?>
                <form method="post" action="<?= e(url('/admin/returns/' . $r['id'])) ?>" class="mt-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reject">
                    <label class="form-label small" for="rj">Reject — reason shown to the customer</label>
                    <input class="form-control form-control-sm<?= invalid('decision_note') ?>" id="rj" name="note" maxlength="300" required>
                    <?= field_error('decision_note') ?>
                    <button class="btn btn-sm btn-outline-danger mt-2" type="submit">Reject return</button>
                </form>
            <?php elseif ($r['status'] === 'approved'): ?>
                <?= $form('receive', 'Mark items received', 'btn-wm-green', 'Confirm the items have been physically received?') ?>
            <?php elseif ($r['status'] === 'received'): ?>
                <?php if ($pending): ?>
                    <p class="small mb-0">Inspect each item (<?= count($pending) ?> left), then complete the return.</p>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/admin/returns/' . $r['id'])) ?>" data-confirm="Complete this return with the refund shown?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="complete">
                        <label class="form-label small" for="ra">Refund amount (KSh)</label>
                        <input class="form-control form-control-sm<?= invalid('refund_amount') ?>" id="ra" name="refund_amount" inputmode="decimal" required value="<?= e($suggested) ?>">
                        <div class="form-text">Up to <?= e(money($maxRefund)) ?> (items at the price paid). Use 0 if no refund is due.</div>
                        <?= field_error('refund_amount') ?>
                        <label class="form-label small mt-2" for="rn">Note (required for 0)</label>
                        <input class="form-control form-control-sm<?= invalid('completion_note') ?>" id="rn" name="note" maxlength="500">
                        <?= field_error('completion_note') ?>
                        <button class="btn btn-sm btn-wm-green mt-2" type="submit">Complete return</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="small text-muted mb-0">This return is <?= e($r['status']) ?>.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
