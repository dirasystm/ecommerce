<?php
use Waimart\Core\Validator;
use Waimart\Services\SellerContext;

/** @var array<string,mixed> $seller @var list<array{id:int,name:string}> $counties @var list<array<string,mixed>> $members @var array<string,mixed>|null $payout */
$canEdit = in_array(SellerContext::role(), ['owner', 'manager'], true) && $seller['status'] === 'active';
?>
<div class="wm-admin-head"><h1>Store profile</h1></div>

<div class="row g-3">
    <div class="col-lg-8">
        <form class="wm-panel" method="post" action="<?= e(url('/seller/profile')) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="wm-panel-head">Public store information</div>
            <fieldset class="wm-panel-body row g-3" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="col-md-6">
                    <div class="form-label">Store name</div>
                    <div class="fw-semibold"><?= e($seller['name']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="form-label">Store address</div>
                    <div class="fw-semibold"><a href="<?= e(url('/store/' . $seller['slug'])) ?>" target="_blank" rel="noopener">/store/<?= e($seller['slug']) ?></a></div>
                </div>
                <div class="col-12 form-text mt-0">To change your store name or address, contact <?= e(brand_name()) ?> Seller Support.</div>

                <div class="col-12">
                    <label class="form-label" for="description">Store description</label>
                    <textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="5" maxlength="2000"><?= e(fv('description', $seller['description'])) ?></textarea>
                    <?= field_error('description') ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="logo">Store logo</label>
                    <?php if ($seller['logo_path']): ?>
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <img src="<?= e(media($seller['logo_path'])) ?>" alt="Current store logo" width="72" height="72" class="rounded border object-fit-contain bg-white">
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1"><label class="form-check-label" for="remove_logo">Remove logo</label></div>
                        </div>
                    <?php endif; ?>
                    <input class="form-control<?= invalid('image') ?>" type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text">JPEG, PNG or WebP, up to 5 MB. Square images look best.</div>
                    <?= field_error('image') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="county_id">County</label>
                    <select class="form-select<?= invalid('county_id') ?>" id="county_id" name="county_id">
                        <option value="">—</option>
                        <?php $cid = (string) fv('county_id', (string) $seller['county_id']); foreach ($counties as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $cid === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('county_id') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="town">Town</label>
                    <input class="form-control<?= invalid('town') ?>" id="town" name="town" maxlength="100" value="<?= e(fv('town', $seller['town'])) ?>">
                    <?= field_error('town') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="public_email">Customer support email</label>
                    <input class="form-control<?= invalid('public_email') ?>" type="email" id="public_email" name="public_email" value="<?= e(fv('public_email', $seller['public_email'])) ?>">
                    <?= field_error('public_email') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="public_phone">Customer support phone</label>
                    <input class="form-control<?= invalid('public_phone') ?>" type="tel" id="public_phone" name="public_phone"
                           value="<?= e(fv('public_phone', $seller['public_phone'] ? Validator::displayPhone($seller['public_phone']) : '')) ?>">
                    <?= field_error('public_phone') ?>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="show_contacts" name="show_contacts" value="1" <?= fchecked('show_contacts', (int) $seller['show_contacts'] === 1) ?>>
                        <label class="form-check-label" for="show_contacts">Show these contact details on my public store page</label>
                    </div>
                    <div class="form-text">Your personal and business addresses, documents and payout details are never shown to customers.</div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="allow_promotions" name="allow_promotions" value="1" <?= fchecked('allow_promotions', (int) $seller['allow_promotions'] === 1) ?>>
                        <label class="form-check-label" for="allow_promotions">Allow <?= e(brand_name()) ?> to include my products in flash sales</label>
                    </div>
                    <div class="form-text">A flash sale sells at a lower price agreed for the sale, and your earnings are based on the price the customer pays. <?= e(brand_name()) ?> cannot add your products to a flash sale unless this is ticked.</div>
                </div>
            </fieldset>
            <?php if ($canEdit): ?><div class="wm-panel-body pt-0"><button class="btn btn-wm-green" type="submit">Save store profile</button></div><?php endif; ?>
        </form>
    </div>
    <div class="col-lg-4">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Payout destination</div>
            <div class="wm-panel-body small"><?= $payout ? e($payout['masked']) : 'No payout details on file.' ?><div class="text-muted mt-1">Changes are made through <?= e(brand_name()) ?> Seller Support for your security.</div></div>
        </section>
        <section class="wm-panel">
            <div class="wm-panel-head">Store team</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($members as $m): ?>
                    <li class="list-group-item d-flex justify-content-between"><span><?= e($m['first_name'] . ' ' . $m['last_name']) ?></span><span class="text-muted"><?= e(ucfirst($m['role'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>
