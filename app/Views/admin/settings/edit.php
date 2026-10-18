<?php
use Waimart\Core\Validator;
use Waimart\Services\SettingsService;

/** @var array<string,string> $values */
$groups = [];
foreach (SettingsService::FIELDS as $key => [$label, $rule, $help, $group]) {
    $groups[$group][$key] = [$label, $rule, $help];
}
?>
<div class="wm-admin-head"><h1>Store settings</h1></div>
<form method="post" action="<?= e(url('/admin/settings')) ?>" style="max-width:860px" novalidate>
    <?= csrf_field() ?>
    <?php foreach ($groups as $group => $fields): ?>
        <section class="wm-panel mb-3">
            <div class="wm-panel-head"><?= e($group) ?></div>
            <div class="wm-panel-body row g-3">
                <?php if ($group === 'M-Pesa'): ?>
                    <div class="col-12"><div class="alert alert-warning small mb-0">Customers pay to these numbers. Double-check every digit — changes are logged in the audit trail.</div></div>
                <?php endif; ?>
                <?php foreach ($fields as $key => [$label, $rule, $help]): $val = (string) fv($key, $values[$key] ?? ''); ?>
                    <?php if ($rule === 'bool'): ?>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= fchecked($key, ($values[$key] ?? '0') === '1') ?>>
                                <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                                <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-md-6">
                            <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                            <input class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>"
                                   value="<?= e($key === 'mpesa_phone_number' && preg_match('/^254\d{9}$/', $val) ? Validator::displayPhone($val) : $val) ?>">
                            <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
                            <?= field_error($key) ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <section class="wm-panel mb-3">
        <div class="wm-panel-head">Seller onboarding documents</div>
        <div class="wm-panel-body row g-3">
            <div class="col-12"><p class="small text-muted mb-0">Choose which documents seller applicants must upload, according to your marketplace's own onboarding policy. Files are stored privately and only staff with seller permissions can open them.</p></div>
            <?php $oldModes = fv('doc_mode', $docModes); ?>
            <?php foreach (\Waimart\Services\SellerDocumentPolicy::TYPES as $type => $typeLabel): $mode = (string) (is_array($oldModes) ? ($oldModes[$type] ?? 'off') : ($docModes[$type] ?? 'off')); ?>
                <div class="col-md-6">
                    <label class="form-label" for="doc_<?= e($type) ?>"><?= e($typeLabel) ?></label>
                    <select class="form-select" id="doc_<?= e($type) ?>" name="doc_mode[<?= e($type) ?>]">
                        <?php foreach (\Waimart\Services\SellerDocumentPolicy::MODES as $m => $mLabel): ?>
                            <option value="<?= e($m) ?>" <?= $mode === $m ? 'selected' : '' ?>><?= e($mLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <?= field_error('doc_mode') ?>
        </div>
    </section>
    <button class="btn btn-wm-green" type="submit">Save settings</button>
</form>
