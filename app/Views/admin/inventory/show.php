<?php
use Waimart\Services\InventoryService;

/** @var array<string,mixed> $v @var list<array<string,mixed>> $movements @var bool $canAdjust @var \Waimart\Core\ListParams $lp @var string $type @var list<string> $types */
$vid = (int) $v['id'];
$available = (int) $v['on_hand'] - (int) $v['reserved'];
$formErr = fn (string $f) => fv('_form', '') !== '' ? field_error($f) : '';
?>
<div class="wm-admin-head">
    <div>
        <h1><?= e($v['product_name']) ?></h1>
        <div class="text-muted small">SKU <code><?= e($v['sku']) ?></code> · <?= e($v['name']) ?></div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/products/' . $v['product_id'] . '#variants')) ?>">Edit product</a>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([['On hand', $v['on_hand'], 'Physical units in the warehouse'], ['Reserved', $v['reserved'], 'Held for placed orders'], ['Available', $available, 'Can be sold now']] as [$l, $n, $hint]): ?>
        <div class="col-4"><div class="wm-panel wm-stat"><div class="wm-stat-label"><?= $l ?></div><div class="wm-stat-value"><?= (int) $n ?></div><div class="small text-muted d-none d-md-block"><?= $hint ?></div></div></div>
    <?php endforeach; ?>
</div>

<?php if ($canAdjust): ?>
<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <form class="wm-panel h-100" method="post" action="<?= e(url("/admin/inventory/$vid/receive")) ?>">
            <?= csrf_field() ?><input type="hidden" name="_form" value="receive">
            <div class="wm-panel-head">Receive stock</div>
            <div class="wm-panel-body">
                <label class="form-label" for="rq">Quantity received</label>
                <input class="form-control" id="rq" name="quantity" type="number" min="1" max="100000" required>
                <?= fv('_form', '') === 'receive' ? field_error('quantity') : '' ?>
                <label class="form-label mt-2" for="rr">Reason</label>
                <select class="form-select" id="rr" name="reason">
                    <?php foreach (InventoryService::RECEIPT_REASONS as $k => $label): if ($k === 'opening') { continue; } ?>
                        <option value="<?= $k ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label mt-2" for="rn">Note / reference</label>
                <input class="form-control" id="rn" name="note" maxlength="255" placeholder="e.g. Supplier invoice no.">
                <button class="btn btn-wm-green mt-3" type="submit">Receive</button>
            </div>
        </form>
    </div>
    <div class="col-lg-4">
        <form class="wm-panel h-100" method="post" action="<?= e(url("/admin/inventory/$vid/adjust")) ?>">
            <?= csrf_field() ?><input type="hidden" name="_form" value="adjust">
            <div class="wm-panel-head">Adjust stock</div>
            <div class="wm-panel-body">
                <label class="form-label" for="ad">Change (+/−)</label>
                <input class="form-control<?= fv('_form', '') === 'adjust' ? invalid('delta') : '' ?>" id="ad" name="delta" type="number" min="-100000" max="100000" required placeholder="e.g. -2">
                <?= fv('_form', '') === 'adjust' ? field_error('delta') : '' ?>
                <label class="form-label mt-2" for="ar">Reason</label>
                <select class="form-select" id="ar" name="reason">
                    <?php foreach (InventoryService::ADJUST_REASONS as $k => $label): if ($k === 'stock_count') { continue; } ?>
                        <option value="<?= $k ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label mt-2" for="an">Note</label>
                <input class="form-control<?= fv('_form', '') === 'adjust' ? invalid('note') : '' ?>" id="an" name="note" maxlength="255">
                <?= fv('_form', '') === 'adjust' ? field_error('note') : '' ?>
                <p class="form-text">Cannot reduce below the <?= (int) $v['reserved'] ?> reserved unit(s).</p>
                <button class="btn btn-outline-wm" type="submit">Adjust</button>
            </div>
        </form>
    </div>
    <div class="col-lg-4">
        <form class="wm-panel h-100" method="post" action="<?= e(url("/admin/inventory/$vid/count")) ?>">
            <?= csrf_field() ?><input type="hidden" name="_form" value="count">
            <div class="wm-panel-head">Record stock count</div>
            <div class="wm-panel-body">
                <label class="form-label" for="cc">Units physically counted</label>
                <input class="form-control<?= fv('_form', '') === 'count' ? invalid('counted') . invalid('delta') : '' ?>" id="cc" name="counted" type="number" min="0" required>
                <?= fv('_form', '') === 'count' ? field_error('counted') . field_error('delta') : '' ?>
                <label class="form-label mt-2" for="cn">Note</label>
                <input class="form-control" id="cn" name="note" maxlength="255" placeholder="e.g. Monthly count">
                <p class="form-text">Sets on-hand to the counted figure and records the difference.</p>
                <button class="btn btn-outline-secondary" type="submit">Record count</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="wm-panel">
    <div class="wm-panel-head">Stock ledger</div>
    <div class="wm-panel-body pb-0"><?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'movements', 'path' => '/admin/inventory/' . $v['id'], 'lp' => $lp,
        'label' => 'Search stock movements', 'placeholder' => 'Reason, note, staff or order number',
        'filters' => [['name' => 'type', 'label' => 'Type', 'value' => $type, 'options' => ['' => 'All types'] + array_combine($types, array_map(fn ($t) => ucfirst(str_replace('_', ' ', $t)), $types))]]]) ?></div>
    <div id="movements" class="wm-table-region" data-table-region data-loading-text="Loading movements…">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <caption class="visually-hidden">Stock movements</caption>
            <thead><tr><?= sort_th('When', 'when', $lp, '/admin/inventory/' . $v['id']) ?><th scope="col">Type</th><th scope="col">Reason / note</th><th scope="col" class="text-end">On hand Δ</th><th scope="col" class="text-end">Reserved Δ</th><th scope="col" class="text-end">On hand</th><th scope="col" class="text-end">Reserved</th><th scope="col">Order</th><th scope="col">By</th></tr></thead>
            <tbody>
            <?php foreach ($movements as $m): ?>
                <tr>
                    <td class="text-nowrap small"><?= e(date('d M Y H:i', strtotime($m['created_at']))) ?></td>
                    <td><?= e(str_replace('_', ' ', $m['movement_type'])) ?></td>
                    <td class="small"><?= e(trim(($m['reason'] ? str_replace('_', ' ', $m['reason']) : '') . ($m['note'] ? ' — ' . $m['note'] : ''), ' —')) ?></td>
                    <td class="text-end"><?= sprintf('%+d', $m['on_hand_delta']) ?></td>
                    <td class="text-end"><?= sprintf('%+d', $m['reserved_delta']) ?></td>
                    <td class="text-end"><?= (int) $m['on_hand_after'] ?></td>
                    <td class="text-end"><?= (int) $m['reserved_after'] ?></td>
                    <td><?= $m['order_id'] ? '#' . (int) $m['order_id'] : '' ?></td>
                    <td class="small"><?= e($m['first_name'] ? $m['first_name'] . ' ' . $m['last_name'] : ucfirst($m['actor_type'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$movements): ?><?= empty_row(9, $lp->q !== '' || $type !== '', 'No movements recorded.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/inventory/' . $v['id'] . '', 'id' => 'movements', 'noun' => 'movements']) ?>
    </div>
</div>
