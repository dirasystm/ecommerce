<?php
use Waimart\Core\Auth;
use Waimart\Services\OrderPresenter;
use Waimart\Services\SettingsService;

/** @var array<string,string> $values @var array<string,string> $labels @var array<string,true> $edited */
$fields = SettingsService::fieldsOf('delivery');
$oldLabels = fv('label', null);   // what was typed, if saving failed
?>
<div class="wm-admin-head"><h1>Delivery settings</h1></div>
<p class="small text-muted">How customers receive orders. Fees, delivery times and cash-on-delivery rules are set per
    <?php if (Auth::can('delivery.manage')): ?><a href="<?= e(url('/admin/delivery-zones')) ?>">delivery zone</a><?php else: ?>delivery zone<?php endif; ?> and per
    <?php if (Auth::can('pickup_points.manage')): ?><a href="<?= e(url('/admin/pickup-points')) ?>">pickup station</a><?php else: ?>pickup station<?php endif; ?>.
    Checkout always works the charges out on the server from these settings.</p>

<form class="wm-panel mb-3" method="post" action="<?= e(url('/admin/delivery-settings')) ?>" style="max-width:860px" novalidate>
    <?= csrf_field() ?>
    <div class="wm-panel-head">Delivery options</div>
    <div class="wm-panel-body row g-3">
        <?php foreach ($fields as $key => [$label, $rule, $help, , $widget]): $helpId = $key . 'Help'; ?>
            <?php if ($widget === 'checkbox'): ?>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input<?= invalid($key) ?>" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= fchecked($key, ($values[$key] ?? '1') === '1') ?> aria-describedby="<?= e($helpId) ?>">
                        <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                        <div class="form-text" id="<?= e($helpId) ?>"><?= e($help) ?></div>
                        <?= field_error($key) ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-md-6">
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <?php if ($widget === 'textarea'): ?>
                        <textarea class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" rows="2" maxlength="300" aria-describedby="<?= e($helpId) ?>"><?= e((string) fv($key, $values[$key] ?? '')) ?></textarea>
                    <?php else: ?>
                        <input class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" inputmode="decimal" value="<?= e((string) fv($key, $values[$key] ?? '')) ?>" aria-describedby="<?= e($helpId) ?>">
                    <?php endif; ?>
                    <div class="form-text" id="<?= e($helpId) ?>"><?= e($help) ?></div>
                    <?= field_error($key) ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="col-12"><button class="btn btn-wm-green" type="submit">Save delivery settings</button></div>
    </div>
</form>

<form class="wm-panel" id="status-names" method="post" action="<?= e(url('/admin/delivery-settings/status-names')) ?>" style="max-width:860px" novalidate>
    <?= csrf_field() ?>
    <div class="wm-panel-head">Order status names</div>
    <div class="wm-panel-body">
        <p class="small text-muted">What customers (and staff) see for each step of an order: on order pages, tracking and messages. Only the wording changes;
            the steps themselves stay the same. Leave a name blank to use the original.</p>
        <div class="row g-2">
            <?php foreach (OrderPresenter::STATUS_LABELS as $status => $default): $id = 'label_' . $status; ?>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="<?= e($id) ?>"><?= e($default) ?><?= isset($edited[$status]) ? ' <span class="badge badge-soft-warning">renamed</span>' : '' ?></label>
                    <input class="form-control form-control-sm<?= invalid("label.$status") ?>" id="<?= e($id) ?>" name="label[<?= e($status) ?>]" maxlength="80"
                           value="<?= e(is_array($oldLabels) ? (string) ($oldLabels[$status] ?? '') : (isset($edited[$status]) ? $labels[$status] : '')) ?>" placeholder="<?= e($default) ?>">
                    <?= field_error("label.$status") ?>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-wm-green mt-3" type="submit">Save status names</button>
    </div>
</form>
