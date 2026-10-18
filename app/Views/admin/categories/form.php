<?php /** @var array<string,mixed>|null $cat @var list<array<string,mixed>> $parents */ ?>
<div class="wm-admin-head">
    <h1><?= $cat ? 'Edit category' : 'New category' ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/categories')) ?>">Back</a>
</div>
<form class="wm-panel wm-panel-body" method="post" action="<?= e(url($cat ? '/admin/categories/' . $cat['id'] : '/admin/categories')) ?>" style="max-width:720px">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="name">Name</label>
        <input class="form-control<?= invalid('name') ?>" id="name" name="name" maxlength="120" required value="<?= e(fv('name', $cat['name'] ?? '')) ?>">
        <?= field_error('name') ?>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label" for="slug">URL slug</label>
            <input class="form-control<?= invalid('slug') ?>" id="slug" name="slug" maxlength="140" value="<?= e(fv('slug', $cat['slug'] ?? '')) ?>" aria-describedby="slugHelp">
            <div id="slugHelp" class="form-text">Leave blank to generate from the name.</div>
            <?= field_error('slug') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="parent_id">Parent category</label>
            <select class="form-select<?= invalid('parent_id') ?>" id="parent_id" name="parent_id">
                <option value="">— Top level —</option>
                <?php foreach ($parents as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (string) fv('parent_id', (string) ($cat['parent_id'] ?? '')) === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('parent_id') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="icon">Icon</label>
            <input class="form-control<?= invalid('icon') ?>" id="icon" name="icon" maxlength="40" value="<?= e(fv('icon', $cat['icon'] ?? '')) ?>" aria-describedby="iconHelp">
            <div id="iconHelp" class="form-text">A Bootstrap Icons name, e.g. <code>phone</code>, <code>tv</code>, <code>basket</code>.</div>
            <?= field_error('icon') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="sort_order">Display order</label>
            <input class="form-control<?= invalid('sort_order') ?>" id="sort_order" name="sort_order" type="number" value="<?= e(fv('sort_order', (string) ($cat['sort_order'] ?? '0'))) ?>">
            <?= field_error('sort_order') ?>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="description">Description</label>
        <textarea class="form-control" id="description" name="description" rows="3"><?= e(fv('description', $cat['description'] ?? '')) ?></textarea>
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= fchecked('is_active', $cat === null || (int) $cat['is_active'] === 1) ?>>
        <label class="form-check-label" for="is_active">Visible in the store</label>
    </div>
    <button class="btn btn-wm-green" type="submit">Save category</button>
</form>
