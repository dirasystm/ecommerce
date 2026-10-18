<?php
use Waimart\Services\SiteContentService;

/** @var array<string,mixed> $s @var list<array<string,mixed>> $categories */
$isNew = !isset($s['id']);
$type = (string) $s['type'];
$f = fn (string $k, string $d = '') => (string) fv($k, $s[$k] ?? $d);
$dt = fn (?string $v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$hasLimit = !in_array($type, ['hero', 'values', 'text'], true);
?>
<div class="wm-admin-head"><h1><?= $isNew ? 'New homepage section' : 'Edit homepage section' ?></h1></div>
<p><a href="<?= e(url('/admin/content/homepage')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Homepage</a></p>
<form class="wm-panel" method="post" action="<?= e(url($isNew ? '/admin/content/homepage/new' : '/admin/content/homepage/' . $s['id'])) ?>" style="max-width:760px" novalidate>
    <?= csrf_field() ?>
    <?php if ($isNew): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <div class="wm-panel-head"><?= e(SiteContentService::SECTION_TYPES[$type]) ?></div>
    <div class="wm-panel-body row g-3">
        <div class="col-md-8">
            <label class="form-label" for="title"><?= $type === 'hero' ? 'Name (for this list)' : 'Heading' ?></label>
            <input class="form-control<?= invalid('title') ?>" id="title" name="title" maxlength="120" value="<?= e($f('title')) ?>">
            <?= field_error('title') ?>
        </div>
        <?php if ($hasLimit): ?>
            <div class="col-md-4">
                <label class="form-label" for="item_limit"><?= $type === 'category_rails' ? 'Number of rows' : 'Items to show' ?></label>
                <input class="form-control<?= invalid('item_limit') ?>" id="item_limit" name="item_limit" inputmode="numeric" value="<?= e($f('item_limit')) ?>" placeholder="default">
                <?= field_error('item_limit') ?>
            </div>
        <?php endif; ?>
        <?php if ($type === 'hero'): ?>
            <div class="col-12">
                <label class="form-label" for="subtitle">Small label above each slide title <span class="text-muted fw-normal">(optional; {store} = store name)</span></label>
                <input class="form-control<?= invalid('subtitle') ?>" id="subtitle" name="subtitle" maxlength="160" value="<?= e($f('subtitle')) ?>">
                <?= field_error('subtitle') ?>
            </div>
        <?php endif; ?>
        <?php if ($type === 'category_row'): ?>
            <div class="col-12">
                <label class="form-label" for="category_id">Category</label>
                <select class="form-select<?= invalid('category_id') ?>" id="category_id" name="category_id">
                    <option value="">Choose…</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $f('category_id') === (string) $c['id'] ? 'selected' : '' ?>><?= $c['parent_name'] ? e($c['parent_name']) . ' › ' : '' ?><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Products from this category and its sub-categories.</div>
                <?= field_error('category_id') ?>
            </div>
        <?php endif; ?>
        <?php if ($type === 'text'): ?>
            <div class="col-12">
                <label class="form-label" for="body">Text</label>
                <textarea class="form-control<?= invalid('body') ?>" id="body" name="body" rows="8" maxlength="5000" aria-describedby="bodyHelp"><?= e($f('body')) ?></textarea>
                <div class="form-text" id="bodyHelp">Same formatting as pages: <code>## heading</code>, <code>- list</code>, <code>**bold**</code>, <code>[link](/flash-sales)</code>, <code>{store}</code>.</div>
                <?= field_error('body') ?>
            </div>
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label" for="starts_at">Show from <span class="text-muted fw-normal">(optional)</span></label>
            <input class="form-control<?= invalid('starts_at') ?>" type="datetime-local" id="starts_at" name="starts_at" value="<?= e((string) fv('starts_at', $dt($s['starts_at'] ?? null))) ?>">
            <?= field_error('starts_at') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="ends_at">Hide after <span class="text-muted fw-normal">(optional)</span></label>
            <input class="form-control<?= invalid('ends_at') ?>" type="datetime-local" id="ends_at" name="ends_at" value="<?= e((string) fv('ends_at', $dt($s['ends_at'] ?? null))) ?>">
            <?= field_error('ends_at') ?>
        </div>
        <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="is_visible" name="is_visible" value="1" <?= fchecked('is_visible', $isNew ? true : (int) $s['is_visible'] === 1) ?>>
                <label class="form-check-label" for="is_visible">Show on the homepage</label></div>
        </div>
        <div class="col-12"><button class="btn btn-wm-green" type="submit">Save section</button></div>
    </div>
</form>
