<?php /** @var array<string,mixed>|null $sale @var list<array<string,mixed>> $items */
$dt = fn ($v) => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';
$saleForm = fv('_form', '') === 'sale';
?>
<div class="wm-admin-head">
    <h1><?= $sale ? e($sale['title']) : 'New flash sale' ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/flash-sales')) ?>">All flash sales</a>
</div>
<div class="row g-3">
    <div class="col-xl-5">
        <form class="wm-panel wm-panel-body" method="post" action="<?= e(url($sale ? '/admin/flash-sales/' . $sale['id'] : '/admin/flash-sales/new')) ?>" novalidate>
            <?= csrf_field() ?><input type="hidden" name="_form" value="sale">
            <div class="mb-3"><label class="form-label" for="title">Title</label><input class="form-control<?= $saleForm ? invalid('title') : '' ?>" id="title" name="title" maxlength="120" required value="<?= e($saleForm ? fv('title') : ($sale['title'] ?? 'Flash Sales')) ?>"><?= $saleForm ? field_error('title') : '' ?></div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label" for="starts_at">Starts</label><input class="form-control<?= $saleForm ? invalid('starts_at') : '' ?>" id="starts_at" name="starts_at" type="datetime-local" required value="<?= e($saleForm ? fv('starts_at') : $dt($sale['starts_at'] ?? null)) ?>"></div>
                <div class="col-6"><label class="form-label" for="ends_at">Ends</label><input class="form-control<?= $saleForm ? invalid('ends_at') : '' ?>" id="ends_at" name="ends_at" type="datetime-local" required value="<?= e($saleForm ? fv('ends_at') : $dt($sale['ends_at'] ?? null)) ?>"></div>
                <div class="col-12"><?= $saleForm ? field_error('starts_at') . field_error('ends_at') . field_error('sku') : '' ?></div>
            </div>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $saleForm ? fchecked('is_active', true) : (($sale === null || (int) $sale['is_active']) ? 'checked' : '') ?>><label class="form-check-label" for="is_active">Active</label></div>
            <button class="btn btn-wm-green" type="submit">Save sale</button>
        </form>
    </div>
    <?php if ($sale): ?>
        <div class="col-xl-7" id="items">
            <section class="wm-panel">
                <div class="wm-panel-head">Items in this sale (<?= count($items) ?>)</div>
                <div class="table-responsive"><table class="table table-sm mb-0 wm-num">
                    <thead><tr><th>SKU</th><th>Product</th><th class="text-end">Normal</th><th class="text-end">Sale</th><th class="text-end">Off</th><th class="text-end">Available</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr><td><code><?= e($it['sku']) ?></code></td><td class="small"><?= e($it['product']) ?><?= $it['variant'] !== 'Default' ? ' · ' . e($it['variant']) : '' ?></td>
                            <td class="text-end"><?= e(money($it['price'])) ?></td><td class="text-end fw-semibold"><?= e(money($it['sale_price'])) ?></td>
                            <td class="text-end"><?= (int) floor(((float) $it['price'] - (float) $it['sale_price']) / (float) $it['price'] * 100) ?>%</td>
                            <td class="text-end<?= (int) $it['available'] <= 0 ? ' text-danger' : '' ?>"><?= (int) $it['available'] ?></td>
                            <td class="text-end"><form method="post" action="<?= e(url('/admin/flash-sales/' . $sale['id'] . '/items/' . $it['variant_id'] . '/delete')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger" type="submit" aria-label="Remove <?= e($it['sku']) ?>">Remove</button></form></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><tr><td colspan="7" class="text-center text-muted py-3">No items yet.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
                <form class="wm-panel-body border-top" method="post" action="<?= e(url('/admin/flash-sales/' . $sale['id'] . '/items')) ?>" novalidate>
                    <?= csrf_field() ?><input type="hidden" name="_form" value="item">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-5"><label class="form-label small" for="sku">SKU</label><input class="form-control form-control-sm text-uppercase<?= fv('_form', '') === 'item' ? invalid('sku') : '' ?>" id="sku" name="sku" required></div>
                        <div class="col-sm-4"><label class="form-label small" for="sale_price">Sale price (KSh)</label><input class="form-control form-control-sm<?= fv('_form', '') === 'item' ? invalid('sale_price') : '' ?>" id="sale_price" name="sale_price" inputmode="decimal" required></div>
                        <div class="col-sm-3 d-grid"><button class="btn btn-sm btn-wm-green" type="submit">Add item</button></div>
                    </div>
                    <?= fv('_form', '') === 'item' ? field_error('sku') . field_error('sale_price') : '' ?>
                    <p class="form-text mb-0">Find SKUs under Catalogue → Inventory. The sale price must be below the normal price.</p>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
