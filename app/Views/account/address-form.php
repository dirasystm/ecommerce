<?php
use Waimart\Core\Auth;
use Waimart\Core\Validator;
use Waimart\Core\View;

/** @var array<string,mixed>|null $addr @var list<array{id:int,name:string}> $counties */
$u = Auth::user();
ob_start(); ?>
<form method="post" action="<?= e(url($addr ? '/account/addresses/' . $addr['id'] : '/account/addresses')) ?>" style="max-width:640px" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-sm-6">
            <label class="form-label" for="recipient_name">Recipient name</label>
            <input class="form-control<?= invalid('recipient_name') ?>" id="recipient_name" name="recipient_name" autocomplete="name" value="<?= e(fv('recipient_name', $addr['recipient_name'] ?? $u['first_name'] . ' ' . $u['last_name'])) ?>">
            <?= field_error('recipient_name') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="phone">Phone number</label>
            <input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" autocomplete="tel" value="<?= e(fv('phone', isset($addr['phone']) ? Validator::displayPhone($addr['phone']) : ($u['phone'] ? Validator::displayPhone($u['phone']) : ''))) ?>">
            <?= field_error('phone') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="county_id">County</label>
            <select class="form-select<?= invalid('county_id') ?>" id="county_id" name="county_id" data-towns-for="town_list" data-towns-url="<?= e(url('/checkout/towns')) ?>">
                <option value="">Select county…</option>
                <?php foreach ($counties as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string) fv('county_id', (string) ($addr['county_id'] ?? '')) === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('county_id') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="town">Town / area</label>
            <input class="form-control<?= invalid('town') ?>" id="town" name="town" list="town_list" autocomplete="address-level2" value="<?= e(fv('town', $addr['town'] ?? '')) ?>">
            <datalist id="town_list"></datalist>
            <?= field_error('town') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="location">Estate, street, building / house no.</label>
            <input class="form-control<?= invalid('location') ?>" id="location" name="location" autocomplete="street-address" value="<?= e(fv('location', $addr['location'] ?? '')) ?>">
            <?= field_error('location') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="instructions">Delivery instructions <span class="text-muted fw-normal">(optional)</span></label>
            <textarea class="form-control<?= invalid('instructions') ?>" id="instructions" name="instructions" rows="2" maxlength="500"><?= e(fv('instructions', $addr['instructions'] ?? '')) ?></textarea>
            <?= field_error('instructions') ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="label">Label <span class="text-muted fw-normal">(optional, e.g. Home, Office)</span></label>
            <input class="form-control" id="label" name="label" maxlength="40" value="<?= e(fv('label', $addr['label'] ?? '')) ?>">
        </div>
        <div class="col-sm-6 d-flex align-items-end">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" <?= fchecked('is_default', (int) ($addr['is_default'] ?? 0) === 1) ?>>
                <label class="form-check-label" for="is_default">Use as my default address</label>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-wm" type="submit">Save address</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/account/addresses')) ?>">Cancel</a>
    </div>
</form>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
