<?php /** @var array<string,mixed>|null $brand */ ?>
<div class="wm-admin-head">
    <h1><?= $brand ? 'Edit brand' : 'New brand' ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/brands')) ?>">Back</a>
</div>
<form class="wm-panel wm-panel-body" method="post" action="<?= e(url($brand ? '/admin/brands/' . $brand['id'] : '/admin/brands')) ?>" style="max-width:560px">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="name">Name</label>
        <input class="form-control<?= invalid('name') ?>" id="name" name="name" maxlength="120" required value="<?= e(fv('name', $brand['name'] ?? '')) ?>">
        <?= field_error('name') ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="slug">URL slug</label>
        <input class="form-control<?= invalid('slug') ?>" id="slug" name="slug" maxlength="140" value="<?= e(fv('slug', $brand['slug'] ?? '')) ?>">
        <div class="form-text">Leave blank to generate from the name.</div>
        <?= field_error('slug') ?>
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= fchecked('is_active', $brand === null || (int) $brand['is_active'] === 1) ?>>
        <label class="form-check-label" for="is_active">Active</label>
    </div>
    <button class="btn btn-wm-green" type="submit">Save brand</button>
</form>
