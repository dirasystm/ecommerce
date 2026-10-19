<?php
use Waimart\Core\Validator;
use Waimart\Services\SettingsService;

/** @var string $code @var array<string,mixed> $m @var array<string,string> $account */
$f = fn (string $k) => (string) fv($k, $m[$k] ?? '');
$mpesa = $code !== 'cod';
?>
<div class="wm-admin-head"><h1><?= e($m['name']) ?></h1></div>
<p><a href="<?= e(url('/admin/payment-methods')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Payment methods</a></p>
<form method="post" action="<?= e(url('/admin/payment-methods/' . $code)) ?>" style="max-width:860px" novalidate>
    <?= csrf_field() ?>
    <section class="wm-panel mb-3">
        <div class="wm-panel-head">At checkout</div>
        <div class="wm-panel-body row g-3">
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input<?= invalid('is_enabled') ?>" type="checkbox" id="is_enabled" name="is_enabled" value="1" <?= fchecked('is_enabled', (int) $m['is_enabled'] === 1) ?> aria-describedby="enabledHelp">
                    <label class="form-check-label" for="is_enabled">Offer this method at checkout</label>
                    <div class="form-text" id="enabledHelp"><?= $mpesa ? 'Customers only see it once the number below is set.' : 'Cash on delivery is also limited by each delivery zone and pickup station, and by the marketplace rule for sellers.' ?></div>
                    <?= field_error('is_enabled') ?>
                </div>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="name">Name</label>
                <input class="form-control<?= invalid('name') ?>" id="name" name="name" maxlength="80" value="<?= e($f('name')) ?>" aria-describedby="nameHelp">
                <div class="form-text" id="nameHelp">Shown at checkout, on orders and in reports.</div>
                <?= field_error('name') ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="short_name">Footer badge</label>
                <input class="form-control<?= invalid('short_name') ?>" id="short_name" name="short_name" maxlength="30" value="<?= e($f('short_name')) ?>">
                <?= field_error('short_name') ?>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="sort_order">Order</label>
                <input class="form-control<?= invalid('sort_order') ?>" id="sort_order" name="sort_order" inputmode="numeric" value="<?= e($f('sort_order')) ?>">
                <?= field_error('sort_order') ?>
            </div>
            <div class="col-12">
                <label class="form-label" for="description">Description under the name</label>
                <input class="form-control<?= invalid('description') ?>" id="description" name="description" maxlength="255" value="<?= e($f('description')) ?>">
                <?= field_error('description') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="min_order_total">Only for orders from (KSh) <span class="text-muted fw-normal">(optional)</span></label>
                <input class="form-control<?= invalid('min_order_total') ?>" id="min_order_total" name="min_order_total" inputmode="decimal" value="<?= e($f('min_order_total')) ?>">
                <?= field_error('min_order_total') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="max_order_total">Only for orders up to (KSh) <span class="text-muted fw-normal">(optional)</span></label>
                <input class="form-control<?= invalid('max_order_total') ?>" id="max_order_total" name="max_order_total" inputmode="decimal" value="<?= e($f('max_order_total')) ?>" aria-describedby="limitHelp">
                <div class="form-text" id="limitHelp">Limits apply to the order total, including delivery. Checked again when the order is placed.</div>
                <?= field_error('max_order_total') ?>
            </div>
        </div>
    </section>

    <?php if ($account): ?>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Where customers pay</div>
            <div class="wm-panel-body row g-3">
                <div class="col-12"><div class="alert alert-warning small mb-0">Customers pay to this number. Double-check every digit: changes are logged in the audit trail.</div></div>
                <?php foreach ($account as $key => $stored): [$label, $rule, $help] = SettingsService::FIELDS[$key];
                    $val = (string) fv($key, $stored);
                    if ($key === 'mpesa_phone_number' && preg_match('/^254\d{9}$/', $val)) {
                        $val = Validator::displayPhone($val);
                    } ?>
                    <div class="col-md-6">
                        <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                        <input class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>"<?= str_contains($rule, 'int') ? ' inputmode="numeric"' : '' ?><?= $help ? ' aria-describedby="' . e($key) . 'Help"' : '' ?>>
                        <?php if ($help): ?><div class="form-text" id="<?= e($key) ?>Help"><?= e($help) ?></div><?php endif; ?>
                        <?= field_error($key) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="wm-panel mb-3">
        <div class="wm-panel-head">Extra instructions for customers</div>
        <div class="wm-panel-body">
            <label class="form-label" for="instructions">Instructions <span class="text-muted fw-normal">(optional)</span></label>
            <textarea class="form-control<?= invalid('instructions') ?>" id="instructions" name="instructions" rows="5" maxlength="3000" aria-describedby="instrHelp"><?= e($f('instructions')) ?></textarea>
            <div class="form-text" id="instrHelp">Shown on the customer's order page<?= $mpesa ? ' under the payment details' : ' while payment is due' ?> and on the How to pay page, after the standard steps.
                Same formatting as pages (<code>**bold**</code>, <code>- list</code>, <code>[link](/contact)</code>); <code>{order_number}</code> and <code>{amount}</code> are filled in for the order.</div>
            <?= field_error('instructions') ?>
        </div>
    </section>
    <button class="btn btn-wm-green" type="submit">Save <?= e($m['name']) ?></button>
</form>
