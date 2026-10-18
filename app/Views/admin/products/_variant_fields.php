<?php
/**
 * Variant fields. $v is an existing variant row or null; $p is an id prefix for unique labels.
 * $withStock adds the opening-stock field (only when creating).
 * @var array<string,mixed>|null $v
 * @var string $p
 * @var bool $withStock
 */
$v ??= null;
$withStock ??= false;
// After a failed submit only the form that was submitted should show the re-submitted values.
$own = fn (string $k, string $d) => (string) (fv('_form', '') === $p ? fv($k, $d) : $d);
?>
<input type="hidden" name="_form" value="<?= e($p) ?>">
<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label" for="<?= $p ?>sku">SKU</label>
        <input class="form-control<?= fv('_form', '') === $p ? invalid('sku') : '' ?>" id="<?= $p ?>sku" name="sku" maxlength="64" required value="<?= e($own('sku', $v['sku'] ?? '')) ?>">
        <?= fv('_form', '') === $p ? field_error('sku') : '' ?>
    </div>
    <div class="col-md-4">
        <label class="form-label" for="<?= $p ?>name">Variant name</label>
        <input class="form-control" id="<?= $p ?>name" name="variant_name" maxlength="120" placeholder="e.g. 128GB · Blue / Size M" value="<?= e($own('variant_name', $v['name'] ?? '')) ?>">
    </div>
    <div class="col-md-2 col-6">
        <label class="form-label" for="<?= $p ?>price">Price (KSh)</label>
        <input class="form-control<?= fv('_form', '') === $p ? invalid('price') : '' ?>" id="<?= $p ?>price" name="price" inputmode="decimal" required value="<?= e($own('price', (string) ($v['price'] ?? ''))) ?>">
        <?= fv('_form', '') === $p ? field_error('price') : '' ?>
    </div>
    <div class="col-md-2 col-6">
        <label class="form-label" for="<?= $p ?>compare">Was price</label>
        <input class="form-control<?= fv('_form', '') === $p ? invalid('compare_at_price') : '' ?>" id="<?= $p ?>compare" name="compare_at_price" inputmode="decimal" value="<?= e($own('compare_at_price', (string) ($v['compare_at_price'] ?? ''))) ?>">
        <?= fv('_form', '') === $p ? field_error('compare_at_price') : '' ?>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="<?= $p ?>attrs">Attributes</label>
        <textarea class="form-control<?= fv('_form', '') === $p ? invalid('attributes') : '' ?>" id="<?= $p ?>attrs" name="attributes" rows="2" placeholder="Colour: Blue&#10;Storage: 128GB"><?= e($own('attributes', (string) ($v['attributes_text'] ?? ''))) ?></textarea>
        <?= fv('_form', '') === $p ? field_error('attributes') : '' ?>
    </div>
    <div class="col-md-3 col-6">
        <label class="form-label" for="<?= $p ?>low">Low-stock alert at</label>
        <input class="form-control" id="<?= $p ?>low" name="low_stock_threshold" type="number" min="0" value="<?= e($own('low_stock_threshold', (string) ($v['low_stock_threshold'] ?? '5'))) ?>">
    </div>
    <?php if ($withStock): ?>
        <div class="col-md-3 col-6">
            <label class="form-label" for="<?= $p ?>stock">Opening stock</label>
            <input class="form-control<?= fv('_form', '') === $p ? invalid('opening_stock') : '' ?>" id="<?= $p ?>stock" name="opening_stock" type="number" min="0" value="<?= e($own('opening_stock', '0')) ?>">
            <?= fv('_form', '') === $p ? field_error('opening_stock') : '' ?>
        </div>
    <?php else: ?>
        <div class="col-md-3 col-6 d-flex align-items-end">
            <div class="form-check mb-2">
                <input type="hidden" name="variant_active" value="0">
                <input class="form-check-input" type="checkbox" id="<?= $p ?>active" name="variant_active" value="1" <?= (int) ($v['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= $p ?>active">Active</label>
            </div>
        </div>
    <?php endif; ?>
</div>
