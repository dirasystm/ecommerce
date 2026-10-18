<?php
use Waimart\Core\View;
use Waimart\Services\SellerContext;
use Waimart\Services\SellerProductService;

/**
 * @var array<string,mixed> $product
 * @var list<array<string,mixed>> $variants
 * @var list<array<string,mixed>> $images
 * @var bool $moderated
 * @var array<string,string> $receiptReasons
 * @var array<string,string> $adjustReasons
 * @var array<string,mixed> $seller
 */
$pid = (int) $product['id'];
$status = (string) $product['moderation_status'];
$active = $seller['status'] === 'active';
$canEdit = $active && in_array(SellerContext::role(), ['owner', 'manager'], true) && $status !== 'archived';
$canStock = $active;
$sellerMode = true;
$live = $status === 'published' && (int) $product['is_active'] === 1 && $active;
?>
<div class="wm-admin-head">
    <h1 class="text-truncate"><?= e($product['name']) ?></h1>
    <div class="d-flex gap-2 align-items-center">
        <?= SellerProductService::badge($status) ?>
        <?php if ($live): ?><a class="btn btn-outline-secondary" href="<?= e(url('/product/' . $product['slug'])) ?>" target="_blank" rel="noopener">View in shop <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a><?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/seller/products')) ?>">All products</a>
    </div>
</div>

<div class="wm-panel wm-panel-body mb-3">
    <?php if ($status === 'draft'): ?>
        <p class="mb-2">This product is a <strong>draft</strong> and is not in the shop. Add at least one photo and a description, then submit it<?= $moderated ? ' for approval' : '' ?>.</p>
    <?php elseif ($status === 'pending'): ?>
        <p class="mb-0">Waiting for Waimart's approval. You will get an email when it has been reviewed. Price and stock changes still apply.</p>
    <?php elseif ($status === 'rejected'): ?>
        <p class="mb-2 text-danger"><strong>Not approved:</strong> <?= e((string) $product['moderation_note']) ?></p>
        <p class="mb-2 small">Make the changes needed, then submit it again.</p>
    <?php elseif ($status === 'published'): ?>
        <p class="mb-0"><?= $live ? 'Live in the shop.' : 'Approved, but not currently shown: it is unlisted' . ($active ? '' : ' or your store is suspended') . '.' ?>
            <?= $moderated ? ' Changing the name, description, category or photos sends it back for approval.' : '' ?></p>
    <?php else: ?>
        <p class="mb-2">Archived — hidden from the shop. Restore it as a draft to sell it again.</p>
    <?php endif; ?>
    <?php if ($active && in_array(SellerContext::role(), ['owner', 'manager'], true)): ?>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if (in_array($status, ['draft', 'rejected'], true)): ?>
                <form method="post" action="<?= e(url("/seller/products/$pid/submit")) ?>"><?= csrf_field() ?>
                    <button class="btn btn-wm-green" type="submit"><?= $moderated ? 'Submit for approval' : 'Publish' ?></button></form>
            <?php endif; ?>
            <?php if ($status === 'archived'): ?>
                <form method="post" action="<?= e(url("/seller/products/$pid/restore")) ?>"><?= csrf_field() ?>
                    <button class="btn btn-outline-secondary" type="submit">Restore as draft</button></form>
            <?php else: ?>
                <form method="post" action="<?= e(url("/seller/products/$pid/archive")) ?>" data-confirm="Archive this product? It will be removed from the shop."><?= csrf_field() ?>
                    <button class="btn btn-outline-danger" type="submit">Archive</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <form class="wm-panel mb-3" method="post" action="<?= e(url('/seller/products/' . $pid)) ?>" novalidate>
            <?= csrf_field() ?><input type="hidden" name="_form" value="product">
            <div class="wm-panel-head">Product details</div>
            <fieldset class="wm-panel-body" <?= $canEdit ? '' : 'disabled' ?>>
                <?= View::partial('admin/products/_fields', get_defined_vars()) ?>
                <?php if ($canEdit): ?><button class="btn btn-wm-green" type="submit">Save product</button><?php endif; ?>
            </fieldset>
        </form>

        <section class="wm-panel mb-3" id="variants" aria-labelledby="variantsTitle">
            <div class="wm-panel-head"><span id="variantsTitle">Options, prices &amp; stock</span></div>
            <?php foreach ($variants as $v): $vp = 'v' . $v['id']; $avail = (int) $v['on_hand'] - (int) $v['reserved']; ?>
                <div class="wm-panel-body border-bottom">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <strong><?= e($v['name']) ?></strong>
                            <?= (int) $v['is_default'] ? '<span class="badge badge-soft-success ms-1">Default</span>' : '' ?>
                            <?= (int) $v['is_active'] ? '' : '<span class="badge badge-soft-muted ms-1">Inactive</span>' ?>
                            <?= $avail <= 0 ? '<span class="badge badge-soft-danger ms-1">Sold out</span>' : ($avail <= (int) $v['low_stock_threshold'] ? '<span class="badge badge-soft-warning ms-1">Low stock</span>' : '') ?>
                        </div>
                        <div class="small">On hand <strong><?= (int) $v['on_hand'] ?></strong> · Reserved for orders <strong><?= (int) $v['reserved'] ?></strong> · Available <strong><?= $avail ?></strong></div>
                    </div>
                    <form method="post" action="<?= e(url("/seller/products/$pid/variants/{$v['id']}")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <fieldset <?= $canEdit ? '' : 'disabled' ?>>
                            <?= View::partial('admin/products/_variant_fields', ['v' => $v, 'p' => $vp]) ?>
                            <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-secondary mt-3" type="submit">Save option</button><?php endif; ?>
                        </fieldset>
                    </form>
                    <?php if ($canEdit && !(int) $v['is_default'] && (int) $v['is_active']): ?>
                        <form method="post" action="<?= e(url("/seller/products/$pid/variants/{$v['id']}/default")) ?>" class="mt-2">
                            <?= csrf_field() ?><button class="btn btn-sm btn-link px-0" type="submit">Make default</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canStock): ?>
                        <details class="mt-2" <?= fv('_stock', '') === (string) $v['id'] ? 'open' : '' ?>>
                            <summary class="small fw-semibold">Update stock</summary>
                            <form class="row g-2 align-items-end mt-1" method="post" action="<?= e(url("/seller/products/$pid/variants/{$v['id']}/stock")) ?>" novalidate>
                                <?= csrf_field() ?><input type="hidden" name="_stock" value="<?= (int) $v['id'] ?>">
                                <div class="col-sm-3">
                                    <label class="form-label small" for="act<?= $vp ?>">Action</label>
                                    <select class="form-select form-select-sm" id="act<?= $vp ?>" name="action">
                                        <option value="receive">Add new stock</option>
                                        <option value="adjust">Adjust (+/−)</option>
                                        <option value="count">Set counted total</option>
                                    </select>
                                </div>
                                <div class="col-sm-2">
                                    <label class="form-label small" for="qty<?= $vp ?>">Quantity</label>
                                    <input class="form-control form-control-sm" id="qty<?= $vp ?>" name="quantity" type="number" min="1" placeholder="Add">
                                </div>
                                <div class="col-sm-2">
                                    <label class="form-label small" for="delta<?= $vp ?>">+/−</label>
                                    <input class="form-control form-control-sm" id="delta<?= $vp ?>" name="delta" type="number" placeholder="e.g. -2">
                                </div>
                                <div class="col-sm-2">
                                    <label class="form-label small" for="count<?= $vp ?>">Counted</label>
                                    <input class="form-control form-control-sm" id="count<?= $vp ?>" name="counted" type="number" min="0">
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label small" for="reason<?= $vp ?>">Reason</label>
                                    <select class="form-select form-select-sm" id="reason<?= $vp ?>" name="reason">
                                        <optgroup label="Adding stock"><?php foreach ($receiptReasons as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></optgroup>
                                        <optgroup label="Adjusting"><?php foreach ($adjustReasons as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></optgroup>
                                    </select>
                                </div>
                                <div class="col-sm-9">
                                    <label class="form-label small" for="note<?= $vp ?>">Note <span class="text-muted">(required for "Other")</span></label>
                                    <input class="form-control form-control-sm" id="note<?= $vp ?>" name="note" maxlength="255">
                                </div>
                                <div class="col-sm-3 d-grid"><button class="btn btn-sm btn-wm-green" type="submit">Update stock</button></div>
                                <?php if (fv('_stock', '') === (string) $v['id']): ?><div class="col-12"><?= field_error('quantity') . field_error('delta') . field_error('counted') . field_error('reason') ?></div><?php endif; ?>
                            </form>
                            <p class="small text-muted mb-0 mt-1">Units reserved for customers' orders cannot be removed. Every change is recorded in the stock history.</p>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($canEdit): ?>
                <details class="wm-panel-body" <?= fv('_form', '') === 'add' ? 'open' : '' ?>>
                    <summary class="fw-semibold">Add an option (size, colour, …)</summary>
                    <form method="post" action="<?= e(url("/seller/products/$pid/variants")) ?>" class="mt-3" novalidate>
                        <?= csrf_field() ?>
                        <?= View::partial('admin/products/_variant_fields', ['v' => null, 'p' => 'add', 'withStock' => true]) ?>
                        <button class="btn btn-sm btn-wm-green mt-3" type="submit">Add option</button>
                    </form>
                </details>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <section class="wm-panel" id="images" aria-labelledby="imagesTitle">
            <div class="wm-panel-head"><span id="imagesTitle">Photos</span><span class="small text-muted fw-normal"><?= count($images) ?>/10</span></div>
            <div class="wm-panel-body">
                <div class="row g-2 mb-3">
                    <?php foreach ($images as $i => $img): ?>
                        <div class="col-6">
                            <div class="wm-img-tile">
                                <?= $i === 0 ? '<span class="badge bg-success wm-img-main">Main</span>' : '' ?>
                                <img src="<?= e(media($img['path'])) ?>" alt="<?= e($img['alt_text'] ?? '') ?>" loading="lazy">
                                <?php if ($canEdit): ?>
                                    <div class="d-flex justify-content-between mt-1">
                                        <?php if ($i > 0): ?>
                                            <form method="post" action="<?= e(url("/seller/products/$pid/images/{$img['id']}/main")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link px-0" type="submit">Make main</button></form>
                                        <?php else: ?><span></span><?php endif; ?>
                                        <form method="post" action="<?= e(url("/seller/products/$pid/images/{$img['id']}/delete")) ?>" data-confirm="Remove this photo?">
                                            <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger px-0" type="submit" aria-label="Remove photo <?= $i + 1 ?>">Remove</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$images): ?><p class="text-muted small mb-0">No photos yet. At least one is needed before you can submit.</p><?php endif; ?>
                </div>
                <?php if ($canEdit && count($images) < 10): ?>
                    <form method="post" action="<?= e(url("/seller/products/$pid/images")) ?>" enctype="multipart/form-data" novalidate>
                        <?= csrf_field() ?>
                        <label class="form-label" for="image">Upload photo</label>
                        <input class="form-control<?= invalid('image') ?>" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
                        <?= field_error('image') ?>
                        <div class="form-text">JPEG, PNG or WebP up to 5 MB. Photos are resized and converted automatically.</div>
                        <label class="form-label mt-2" for="alt_text">Photo description</label>
                        <input class="form-control" id="alt_text" name="alt_text" maxlength="200" placeholder="e.g. Front view in black">
                        <button class="btn btn-sm btn-wm-green mt-2" type="submit">Upload</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
