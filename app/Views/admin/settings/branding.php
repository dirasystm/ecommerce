<?php
use Waimart\Services\Brand;
use Waimart\Services\BrandingService;

/** @var array<string,string> $values @var array<string,string> $palette */
$previews = ['logo_header' => 'brand_logo_header', 'logo_icon' => 'brand_logo_icon', 'logo_full' => 'brand_logo_full', 'og_image' => 'brand_og_image'];
?>
<div class="wm-admin-head"><h1>Settings</h1></div>
<div class="row g-3">
    <div class="col-lg-3"><?= \Waimart\Core\View::partial('admin/settings/_nav', ['group' => 'branding']) ?></div>
    <div class="col-lg-9">
<p class="small text-muted">The store's name, colours and images, used across the shop, Seller Centre, admin, e-mails and text messages. Changes are live as soon as you save, and every change is recorded in the audit log.</p>
<form method="post" action="<?= e(url('/admin/settings/branding')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <section class="wm-panel mb-3">
        <div class="wm-panel-head">Name</div>
        <div class="wm-panel-body row g-3">
            <div class="col-md-6">
                <label class="form-label" for="store_name">Store name</label>
                <input class="form-control<?= invalid('store_name') ?>" id="store_name" name="store_name" maxlength="60" required value="<?= e((string) fv('store_name', $values['store_name'])) ?>">
                <?= field_error('store_name') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="store_tagline">Tagline</label>
                <input class="form-control<?= invalid('store_tagline') ?>" id="store_tagline" name="store_tagline" maxlength="120" value="<?= e((string) fv('store_tagline', $values['store_tagline'])) ?>">
                <?= field_error('store_tagline') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="order_number_prefix">Order number prefix</label>
                <input class="form-control text-uppercase<?= invalid('order_number_prefix') ?>" id="order_number_prefix" name="order_number_prefix" maxlength="2" pattern="[A-Za-z]{2}" value="<?= e((string) fv('order_number_prefix', $values['order_number_prefix'])) ?>" aria-describedby="prefixHelp">
                <div class="form-text" id="prefixHelp">Two letters at the start of new order numbers, e.g. <?= e($values['order_number_prefix']) ?>-7K3F9Q2X. Existing orders keep their numbers, and their links keep working.</div>
                <?= field_error('order_number_prefix') ?>
            </div>
        </div>
    </section>

    <section class="wm-panel mb-3">
        <div class="wm-panel-head">Colours</div>
        <div class="wm-panel-body row g-3">
            <?php foreach (['brand_primary' => ['Primary colour', 'Header, links and main buttons. Must be dark enough for white text (4.5:1).'],
                            'brand_secondary' => ['Accent colour', 'Highlights, prices and call-to-action buttons. Buttons automatically use a slightly deeper shade when needed so white text stays readable.']] as $key => [$label, $help]): ?>
                <div class="col-md-6">
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <div class="input-group">
                        <input class="form-control form-control-color" type="color" value="<?= e(strtolower($values[$key])) ?>" aria-label="<?= e($label) ?> picker" data-sync="<?= e($key) ?>">
                        <input class="form-control font-monospace<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" maxlength="7" value="<?= e((string) fv($key, $values[$key])) ?>" aria-describedby="<?= e($key) ?>Help">
                    </div>
                    <div class="form-text" id="<?= e($key) ?>Help"><?= e($help) ?></div>
                    <?= field_error($key) ?>
                </div>
            <?php endforeach; ?>
            <div class="col-12 small">
                <span class="me-2">In use now:</span>
                <?php foreach (['--wm-green' => 'Primary', '--wm-green-700' => 'Primary (dark)', '--wm-orange' => 'Accent', '--wm-action' => 'Button'] as $var => $label): ?>
                    <span class="d-inline-flex align-items-center me-3"><span class="d-inline-block border rounded me-1" style="width:18px;height:18px;background:<?= e($palette[$var]) ?>"></span><?= e($label) ?> <code class="ms-1"><?= e($palette[$var]) ?></code></span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="wm-panel mb-3">
        <div class="wm-panel-head">Images</div>
        <div class="wm-panel-body row g-4">
            <?php foreach (BrandingService::UPLOADS as $field => [$label, $help]): ?>
                <div class="col-md-6">
                    <div class="fw-semibold mb-1"><?= e($label) ?></div>
                    <div class="border rounded p-2 mb-2 bg-white text-center"><img src="<?= e(Brand::asset($previews[$field])) ?>" alt="Current <?= e(strtolower($label)) ?>" style="max-height:90px;max-width:100%"></div>
                    <label class="form-label small" for="<?= e($field) ?>">Replace with a new image</label>
                    <input class="form-control form-control-sm<?= invalid($field) ?>" type="file" id="<?= e($field) ?>" name="<?= e($field) ?>" accept="image/png,image/jpeg,image/webp" aria-describedby="<?= e($field) ?>Help">
                    <div class="form-text" id="<?= e($field) ?>Help"><?= e($help) ?> PNG, JPEG or WebP, up to 5 MB.</div>
                    <?= field_error($field) ?>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" id="reset_<?= e($field) ?>" name="reset[]" value="<?= e($field) ?>">
                        <label class="form-check-label small" for="reset_<?= e($field) ?>">Use the original image again</label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <button class="btn btn-wm-green" type="submit">Save branding</button>
</form>
    </div>
</div>
