<?php
use Waimart\Core\Validator;

/**
 * @var array<string,mixed>|null $app the draft being edited, if any
 * @var array<string,mixed> $prefill
 * @var list<array{id:int,name:string}> $counties
 * @var list<array<string,mixed>> $categories
 */
$p = fn (string $k) => fv($k, $prefill[$k] ?? '');
$phone = (string) $p('phone');
if (preg_match('/^254\d{9}$/', $phone)) {
    $phone = Validator::displayPhone($phone);
}
$chosen = fv('category_ids', array_filter(explode(',', (string) ($prefill['category_ids'] ?? ''))));
$chosen = array_map('strval', is_array($chosen) ? $chosen : []);
$type = (string) ($p('business_type') ?: 'individual');
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('/sell')) ?>">Sell on Waimart</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $app ? 'Edit application' : 'Apply' ?></li>
        </ol>
    </nav>
    <section class="wm-card wm-page">
        <h1><?= e($title) ?></h1>
        <p class="text-muted">Step 1 of 2: your business details. You will upload documents and add payout details next. Nothing is sent for review until you submit.</p>

        <form method="post" action="<?= e(url('/sell/apply')) ?>" novalidate style="max-width:820px">
            <?= csrf_field() ?>
            <fieldset class="mb-4">
                <legend class="h5">Your store</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="store_name">Store name</label>
                        <input class="form-control<?= invalid('store_name') ?>" id="store_name" name="store_name" maxlength="120" required value="<?= e($p('store_name')) ?>">
                        <div class="form-text">Shown to customers. It may not include "Waimart".</div>
                        <?= field_error('store_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="store_slug">Store address <span class="text-muted fw-normal">(optional)</span></label>
                        <div class="input-group">
                            <span class="input-group-text">/store/</span>
                            <input class="form-control<?= invalid('store_slug') ?>" id="store_slug" name="store_slug" maxlength="60" value="<?= e($p('store_slug')) ?>" aria-describedby="slugHelp">
                        </div>
                        <div class="form-text" id="slugHelp">Lowercase letters, numbers and hyphens. Leave blank to use your store name.</div>
                        <?= field_error('store_slug') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="business_description">What do you sell?</label>
                        <textarea class="form-control<?= invalid('business_description') ?>" id="business_description" name="business_description" rows="4" maxlength="2000" required><?= e($p('business_description')) ?></textarea>
                        <div class="form-text">At least 30 characters: your products, where they come from and how long you have been selling.</div>
                        <?= field_error('business_description') ?>
                    </div>
                    <div class="col-12">
                        <fieldset>
                            <legend class="form-label fs-6">Product categories you plan to sell in</legend>
                            <div class="row g-1">
                                <?php foreach ($categories as $c): ?>
                                    <div class="col-sm-6 col-lg-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="cat<?= (int) $c['id'] ?>" name="category_ids[]" value="<?= (int) $c['id'] ?>" <?= in_array((string) $c['id'], $chosen, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cat<?= (int) $c['id'] ?>"><?= e($c['name']) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?= field_error('category_ids') ?>
                        </fieldset>
                    </div>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend class="h5">Your business</legend>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="business_type" id="bt_individual" value="individual" <?= $type === 'individual' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="bt_individual">Individual / sole trader</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="business_type" id="bt_business" value="registered_business" <?= $type === 'registered_business' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="bt_business">Registered business</label>
                        </div>
                        <?= field_error('business_type') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="legal_name">Registered business name <span class="text-muted fw-normal">(registered businesses)</span></label>
                        <input class="form-control<?= invalid('legal_name') ?>" id="legal_name" name="legal_name" maxlength="160" value="<?= e($p('legal_name')) ?>">
                        <?= field_error('legal_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="registration_number">Business registration number <span class="text-muted fw-normal">(if any)</span></label>
                        <input class="form-control<?= invalid('registration_number') ?>" id="registration_number" name="registration_number" maxlength="60" value="<?= e($p('registration_number')) ?>">
                        <?= field_error('registration_number') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="county_id">County</label>
                        <select class="form-select<?= invalid('county_id') ?>" id="county_id" name="county_id" required>
                            <option value="">Select county…</option>
                            <?php $cid = (string) $p('county_id'); foreach ($counties as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $cid === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error('county_id') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="town">Town</label>
                        <input class="form-control<?= invalid('town') ?>" id="town" name="town" maxlength="100" required value="<?= e($p('town')) ?>">
                        <?= field_error('town') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="business_address">Business address <span class="text-muted fw-normal">(if you have premises)</span></label>
                        <input class="form-control<?= invalid('business_address') ?>" id="business_address" name="business_address" maxlength="255" value="<?= e($p('business_address')) ?>">
                        <div class="form-text">Kept private — never shown on your store page.</div>
                        <?= field_error('business_address') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend class="h5">Contact person</legend>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="contact_name">Full name</label>
                        <input class="form-control<?= invalid('contact_name') ?>" id="contact_name" name="contact_name" autocomplete="name" maxlength="160" required value="<?= e($p('contact_name')) ?>">
                        <?= field_error('contact_name') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control<?= invalid('email') ?>" type="email" id="email" name="email" autocomplete="email" required value="<?= e($p('email')) ?>">
                        <?= field_error('email') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control<?= invalid('phone') ?>" type="tel" id="phone" name="phone" autocomplete="tel" required value="<?= e($phone) ?>">
                        <?= field_error('phone') ?>
                    </div>
                </div>
            </fieldset>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-wm" type="submit">Save and continue</button>
                <a class="btn btn-outline-secondary" href="<?= e(url($app ? '/sell/application' : '/sell')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</div>
