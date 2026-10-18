<?php
use Waimart\Core\Auth;
use Waimart\Core\View;

/**
 * @var array<string,mixed> $product
 * @var list<array<string,mixed>> $variants
 * @var list<array<string,mixed>> $images
 */
$pid = (int) $product['id'];
?>
<div class="wm-admin-head">
    <h1 class="text-truncate"><?= e($product['name']) ?></h1>
    <div class="d-flex gap-2">
        <?php if ((int) $product['is_active'] === 1 && $product['moderation_status'] === 'published' && $product['seller_status'] === 'active'): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/product/' . $product['slug'])) ?>" target="_blank" rel="noopener">View in store <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/admin/products')) ?>">All products</a>
    </div>
</div>

<div class="wm-panel wm-panel-body mb-3 d-flex flex-wrap align-items-center gap-3 small">
    <span>Seller: <strong><?= e($product['seller_name']) ?></strong><?= (int) $product['seller_is_platform'] ? ' <span class="badge badge-soft-muted">Platform</span>' : '' ?>
        <?= $product['seller_status'] !== 'active' ? ' <span class="badge badge-soft-danger">Store suspended — product hidden</span>' : '' ?></span>
    <span>Approval: <?= \Waimart\Services\SellerProductService::badge((string) $product['moderation_status']) ?></span>
    <?php if ($product['moderation_note']): ?><span class="text-muted">Note: <?= e($product['moderation_note']) ?></span><?php endif; ?>
</div>
<?php if (!(int) $product['seller_is_platform'] && Auth::can('products.moderate') && in_array($product['moderation_status'], ['pending', 'published'], true)): ?>
    <div class="wm-panel wm-panel-body mb-3 d-flex flex-wrap gap-2 align-items-end">
        <?php if ($product['moderation_status'] === 'pending'): ?>
            <form method="post" action="<?= e(url('/admin/moderation/products/' . $pid . '/approve')) ?>">
                <?= csrf_field() ?><button class="btn btn-wm-green" type="submit">Approve &amp; publish</button>
            </form>
        <?php endif; ?>
        <form class="d-flex flex-wrap gap-2 align-items-end" method="post" action="<?= e(url('/admin/moderation/products/' . $pid . '/reject')) ?>" novalidate>
            <?= csrf_field() ?><input type="hidden" name="back" value="edit">
            <div>
                <label class="form-label small mb-1" for="reason"><?= $product['moderation_status'] === 'pending' ? 'Reject' : 'Take down' ?> — reason for the seller</label>
                <input class="form-control form-control-sm<?= invalid('reason') ?>" id="reason" name="reason" maxlength="500" style="min-width:280px">
                <?= field_error('reason') ?>
            </div>
            <button class="btn btn-sm btn-outline-danger" type="submit"><?= $product['moderation_status'] === 'pending' ? 'Reject' : 'Take down' ?></button>
        </form>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-8">
        <form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/products/' . $pid)) ?>">
            <?= csrf_field() ?><input type="hidden" name="_form" value="product">
            <div class="wm-panel-head">Product details</div>
            <div class="wm-panel-body">
                <?= View::partial('admin/products/_fields', get_defined_vars()) ?>
                <button class="btn btn-wm-green" type="submit">Save product</button>
            </div>
        </form>

        <section class="wm-panel mb-3" id="variants" aria-labelledby="variantsTitle">
            <div class="wm-panel-head"><span id="variantsTitle">Variants &amp; stock</span></div>
            <?php foreach ($variants as $v): $vp = 'v' . $v['id']; ?>
                <div class="wm-panel-body border-bottom">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <strong><?= e($v['name']) ?></strong>
                            <?= (int) $v['is_default'] ? '<span class="badge badge-soft-success ms-1">Default</span>' : '' ?>
                            <?= (int) $v['is_active'] ? '' : '<span class="badge badge-soft-muted ms-1">Inactive</span>' ?>
                        </div>
                        <div class="small">
                            On hand <strong><?= (int) $v['on_hand'] ?></strong> ·
                            Reserved <strong><?= (int) $v['reserved'] ?></strong> ·
                            Available <strong><?= (int) $v['on_hand'] - (int) $v['reserved'] ?></strong>
                            <?php if (Auth::can('inventory.view')): ?>
                                · <a href="<?= e(url('/admin/inventory/' . $v['id'])) ?>">Manage stock</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="post" action="<?= e(url("/admin/products/$pid/variants/{$v['id']}")) ?>">
                        <?= csrf_field() ?>
                        <?= View::partial('admin/products/_variant_fields', ['v' => $v, 'p' => $vp]) ?>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Save variant</button>
                        </div>
                    </form>
                    <?php if (!(int) $v['is_default'] && (int) $v['is_active']): ?>
                        <form method="post" action="<?= e(url("/admin/products/$pid/variants/{$v['id']}/default")) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-link px-0" type="submit">Make default</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <details class="wm-panel-body" <?= fv('_form', '') === 'add' ? 'open' : '' ?>>
                <summary class="fw-semibold">Add a variant</summary>
                <form method="post" action="<?= e(url("/admin/products/$pid/variants")) ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <?= View::partial('admin/products/_variant_fields', ['v' => null, 'p' => 'add', 'withStock' => true]) ?>
                    <button class="btn btn-sm btn-wm-green mt-3" type="submit">Add variant</button>
                </form>
            </details>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="wm-panel" id="images" aria-labelledby="imagesTitle">
            <div class="wm-panel-head"><span id="imagesTitle">Images</span><span class="small text-muted fw-normal"><?= count($images) ?>/10</span></div>
            <div class="wm-panel-body">
                <div class="row g-2 mb-3">
                    <?php foreach ($images as $i => $img): ?>
                        <div class="col-6">
                            <div class="wm-img-tile">
                                <?= $i === 0 ? '<span class="badge bg-success wm-img-main">Main</span>' : '' ?>
                                <img src="<?= e(media($img['path'])) ?>" alt="<?= e($img['alt_text'] ?? '') ?>" loading="lazy">
                                <div class="d-flex justify-content-between mt-1">
                                    <?php if ($i > 0): ?>
                                        <form method="post" action="<?= e(url("/admin/products/$pid/images/{$img['id']}/main")) ?>">
                                            <?= csrf_field() ?><button class="btn btn-sm btn-link px-0" type="submit">Make main</button>
                                        </form>
                                    <?php else: ?><span></span><?php endif; ?>
                                    <form method="post" action="<?= e(url("/admin/products/$pid/images/{$img['id']}/delete")) ?>" data-confirm="Remove this image?">
                                        <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger px-0" type="submit" aria-label="Remove image <?= $i + 1 ?>">Remove</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$images): ?><p class="text-muted small mb-0">No images yet. A placeholder is shown in the store.</p><?php endif; ?>
                </div>
                <?php if (count($images) < 10): ?>
                    <form method="post" action="<?= e(url("/admin/products/$pid/images")) ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <label class="form-label" for="image">Upload image</label>
                        <input class="form-control<?= invalid('image') ?>" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" required>
                        <?= field_error('image') ?>
                        <div class="form-text">JPEG, PNG or WebP up to 5 MB. Images are resized and converted to WebP.</div>
                        <label class="form-label mt-2" for="alt_text">Image description (alt text)</label>
                        <input class="form-control" id="alt_text" name="alt_text" maxlength="200" placeholder="e.g. Front view in black">
                        <button class="btn btn-sm btn-wm-green mt-2" type="submit">Upload</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
