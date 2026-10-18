<?php /** @var array<string,mixed>|null $b */
$dt = fn ($v) => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';
?>
<div class="wm-admin-head">
    <h1><?= $b ? 'Edit banner' : 'New banner' ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/banners')) ?>">All banners</a>
</div>
<form class="wm-panel wm-panel-body" method="post" action="<?= e(url($b ? '/admin/banners/' . $b['id'] : '/admin/banners/new')) ?>" enctype="multipart/form-data" style="max-width:760px" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-12"><label class="form-label" for="title">Headline</label><input class="form-control<?= invalid('title') ?>" id="title" name="title" maxlength="120" required value="<?= e(fv('title', $b['title'] ?? '')) ?>"><?= field_error('title') ?></div>
        <div class="col-12"><label class="form-label" for="subtitle">Subtitle</label><input class="form-control<?= invalid('subtitle') ?>" id="subtitle" name="subtitle" maxlength="200" value="<?= e(fv('subtitle', $b['subtitle'] ?? '')) ?>"><?= field_error('subtitle') ?></div>
        <div class="col-md-8"><label class="form-label" for="link_url">Link (internal path)</label><input class="form-control<?= invalid('link_url') ?>" id="link_url" name="link_url" maxlength="255" placeholder="/category/phones-tablets" value="<?= e(fv('link_url', $b['link_url'] ?? '')) ?>"><?= field_error('link_url') ?></div>
        <div class="col-md-4"><label class="form-label" for="sort_order">Order</label><input class="form-control" id="sort_order" name="sort_order" type="number" value="<?= e(fv('sort_order', (string) ($b['sort_order'] ?? '0'))) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="starts_at">Show from (optional)</label><input class="form-control<?= invalid('starts_at') ?>" id="starts_at" name="starts_at" type="datetime-local" value="<?= e(fv('starts_at', $dt($b['starts_at'] ?? null))) ?>"><?= field_error('starts_at') ?></div>
        <div class="col-md-6"><label class="form-label" for="ends_at">Show until (optional)</label><input class="form-control<?= invalid('ends_at') ?>" id="ends_at" name="ends_at" type="datetime-local" value="<?= e(fv('ends_at', $dt($b['ends_at'] ?? null))) ?>"><?= field_error('ends_at') ?></div>
        <div class="col-12">
            <label class="form-label" for="image">Background image (optional)</label>
            <?php if (!empty($b['image_path'])): ?>
                <div class="mb-2"><img src="<?= e(media($b['image_path'])) ?>" alt="" style="max-width:320px" class="rounded border"></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1"><label class="form-check-label" for="remove_image">Remove image (use a text slide)</label></div>
            <?php endif; ?>
            <input class="form-control<?= invalid('image') ?>" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
            <div class="form-text">Wide image, at least 1200×400. Converted to WebP. Without an image the slide uses the brand colours.</div>
            <?= field_error('image') ?>
        </div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= fchecked('is_active', $b === null || (int) $b['is_active'] === 1) ?>><label class="form-check-label" for="is_active">Active</label></div></div>
    </div>
    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-wm-green" type="submit">Save banner</button>
    </div>
</form>
<?php if ($b): ?>
    <form method="post" action="<?= e(url('/admin/banners/' . $b['id'] . '/delete')) ?>" class="mt-2" data-confirm="Delete this banner?">
        <?= csrf_field() ?><button class="btn btn-link text-danger px-0" type="submit">Delete banner</button>
    </form>
<?php endif; ?>
