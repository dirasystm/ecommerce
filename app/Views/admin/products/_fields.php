<?php
/**
 * Product-level fields shared by create and edit.
 * @var array<string,mixed>|null $product
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $brands
 */
use Waimart\Services\CatalogueAdminService;

$product ??= null;
// Seller Centre: the URL slug and the featured flag are platform-controlled, so they are not shown.
$sellerMode ??= false;
// Re-submitted values apply only when this product form (or the create form) was the one submitted.
$mine = in_array(fv("_form", ""), ["product", "new"], true);
$pv = fn (string $k, string $d) => (string) ($mine ? fv($k, $d) : $d);
$pc = fn (string $k, bool $d) => $mine ? fchecked($k, $d) : ($d ? "checked" : "");
$pe = fn (string $k) => $mine ? field_error($k) : "";
$pi = fn (string $k) => $mine ? invalid($k) : "";
?>
<div class="mb-3">
    <label class="form-label" for="name">Product name</label>
    <input class="form-control<?= $pi('name') ?>" id="name" name="name" maxlength="200" required value="<?= e($pv('name', $product['name'] ?? '')) ?>">
    <?= $pe('name') ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label" for="category_id">Category</label>
        <select class="form-select<?= $pi('category_id') ?>" id="category_id" name="category_id" required>
            <option value="">Select…</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $pv('category_id', (string) ($product['category_id'] ?? '')) === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= $c['parent_name'] ? e($c['parent_name']) . ' › ' : '' ?><?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?= $pe('category_id') ?>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="brand_id">Brand</label>
        <select class="form-select<?= $pi('brand_id') ?>" id="brand_id" name="brand_id">
            <option value="">— No brand —</option>
            <?php foreach ($brands as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $pv('brand_id', (string) ($product['brand_id'] ?? '')) === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $pe('brand_id') ?>
    </div>
    <?php if (!$sellerMode): ?>
    <div class="col-md-6">
        <label class="form-label" for="slug">URL slug</label>
        <input class="form-control<?= $pi('slug') ?>" id="slug" name="slug" maxlength="220" value="<?= e($pv('slug', $product['slug'] ?? '')) ?>">
        <div class="form-text">Leave blank to generate from the name.</div>
        <?= $pe('slug') ?>
    </div>
    <?php endif; ?>
    <div class="col-md-6">
        <label class="form-label" for="max_per_order">Maximum per order</label>
        <input class="form-control<?= $pi('max_per_order') ?>" id="max_per_order" name="max_per_order" type="number" min="1" max="100" required value="<?= e($pv('max_per_order', (string) ($product['max_per_order'] ?? '10'))) ?>">
        <?= $pe('max_per_order') ?>
    </div>
</div>
<div class="mb-3">
    <label class="form-label" for="short_description">Short description</label>
    <input class="form-control<?= $pi('short_description') ?>" id="short_description" name="short_description" maxlength="500" value="<?= e($pv('short_description', $product['short_description'] ?? '')) ?>">
    <?= $pe('short_description') ?>
</div>
<div class="mb-3">
    <label class="form-label" for="description">Description</label>
    <textarea class="form-control<?= $pi('description') ?>" id="description" name="description" rows="6"><?= e($pv('description', $product['description'] ?? '')) ?></textarea>
    <div class="form-text">Plain text. Line breaks are kept.</div>
    <?= $pe('description') ?>
</div>
<div class="mb-3">
    <label class="form-label" for="specifications">Specifications</label>
    <textarea class="form-control<?= $pi('specifications') ?>" id="specifications" name="specifications" rows="5" placeholder="Screen: 6.6 inches&#10;Battery: 5000 mAh"><?= e($pv('specifications', CatalogueAdminService::pairsToText($product['specifications'] ?? null))) ?></textarea>
    <div class="form-text">One per line as <code>Label: value</code>.</div>
    <?= $pe('specifications') ?>
</div>
<div class="d-flex flex-wrap gap-4 mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $pc('is_active', $product === null || (int) $product['is_active'] === 1) ?>>
        <label class="form-check-label" for="is_active"><?= $sellerMode ? 'Listed (shown in the shop once approved)' : 'Active (visible in store)' ?></label>
    </div>
    <?php if (!$sellerMode): ?>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1" <?= $pc('is_featured', (int) ($product['is_featured'] ?? 0) === 1) ?>>
        <label class="form-check-label" for="is_featured">Featured on home page</label>
    </div>
    <?php endif; ?>
</div>
