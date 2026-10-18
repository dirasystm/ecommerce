<?php
use Waimart\Core\Validator;
use Waimart\Services\SellerDocumentPolicy;
use Waimart\Services\SettingsService;

/** @var string $group @var array<string,string> $values @var array<string,string> $docModes */
[$groupTitle, $groupDesc] = SettingsService::GROUPS[$group];
$fields = SettingsService::fieldsOf($group);
?>
<div class="wm-admin-head"><h1>Settings</h1></div>
<div class="row g-3">
    <div class="col-lg-3"><?= \Waimart\Core\View::partial('admin/settings/_nav', ['group' => $group]) ?></div>
    <div class="col-lg-9">
        <form method="post" action="<?= e(url('/admin/settings/' . $group)) ?>" novalidate>
            <?= csrf_field() ?>
            <section class="wm-panel mb-3">
                <div class="wm-panel-head"><?= e($groupTitle) ?></div>
                <div class="wm-panel-body row g-3">
                    <div class="col-12"><p class="small text-muted mb-0"><?= e($groupDesc) ?></p></div>
                    <?php if ($group === 'payments'): ?>
                        <div class="col-12"><div class="alert alert-warning small mb-0">Customers pay to these numbers. Double-check every digit — changes are logged in the audit trail.</div></div>
                    <?php endif; ?>
                    <?php if ($group === 'maintenance' && ($values['maintenance_mode'] ?? '0') === '1'): ?>
                        <div class="col-12"><div class="alert alert-danger small mb-0"><strong>The shop is closed for maintenance.</strong> Customers see the maintenance message.</div></div>
                    <?php endif; ?>
                    <?php foreach ($fields as $key => [$label, $rule, $help, , $widget]):
                        $val = (string) fv($key, $values[$key] ?? '');
                        if (in_array($key, ['mpesa_phone_number', 'whatsapp_number'], true) && preg_match('/^254\d{9}$/', $val)) {
                            $val = Validator::displayPhone($val);
                        }
                        $helpId = $help ? $key . 'Help' : null; ?>
                        <?php if ($widget === 'checkbox'): ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= fchecked($key, ($values[$key] ?? '0') === '1') ?><?= $helpId ? ' aria-describedby="' . e($helpId) . '"' : '' ?>>
                                    <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                                    <?php if ($help): ?><div class="form-text" id="<?= e($helpId) ?>"><?= e($help) ?></div><?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="<?= $widget === 'textarea' ? 'col-12' : 'col-md-6' ?>">
                                <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                                <?php if ($widget === 'textarea'): ?>
                                    <textarea class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" rows="3"<?= preg_match('/max:(\d+)/', $rule, $m) ? ' maxlength="' . $m[1] . '"' : '' ?><?= $helpId ? ' aria-describedby="' . e($helpId) . '"' : '' ?>><?= e($val) ?></textarea>
                                <?php else: ?>
                                    <input class="form-control<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>"
                                           <?= $widget === 'number' ? 'inputmode="numeric"' : '' ?><?= str_contains($rule, 'url') ? ' type="url" placeholder="https://"' : '' ?><?= str_contains($rule, 'email') ? ' type="email"' : '' ?><?= $helpId ? ' aria-describedby="' . e($helpId) . '"' : '' ?>>
                                <?php endif; ?>
                                <?php if ($help): ?><div class="form-text" id="<?= e($helpId) ?>"><?= e($help) ?></div><?php endif; ?>
                                <?= field_error($key) ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($group === 'marketplace'): ?>
                <section class="wm-panel mb-3">
                    <div class="wm-panel-head">Seller onboarding documents</div>
                    <div class="wm-panel-body row g-3">
                        <div class="col-12"><p class="small text-muted mb-0">Choose which documents seller applicants must upload, according to your marketplace's own onboarding policy. Files are stored privately and only staff with seller permissions can open them.</p></div>
                        <?php $oldModes = fv('doc_mode', $docModes); ?>
                        <?php foreach (SellerDocumentPolicy::TYPES as $type => $typeLabel): $mode = (string) (is_array($oldModes) ? ($oldModes[$type] ?? 'off') : ($docModes[$type] ?? 'off')); ?>
                            <div class="col-md-6">
                                <label class="form-label" for="doc_<?= e($type) ?>"><?= e($typeLabel) ?></label>
                                <select class="form-select" id="doc_<?= e($type) ?>" name="doc_mode[<?= e($type) ?>]">
                                    <?php foreach (SellerDocumentPolicy::MODES as $m => $mLabel): ?>
                                        <option value="<?= e($m) ?>" <?= $mode === $m ? 'selected' : '' ?>><?= e($mLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                        <?= field_error('doc_mode') ?>
                    </div>
                </section>
            <?php endif; ?>
            <button class="btn btn-wm-green" type="submit">Save <?= e(strtolower($groupTitle)) ?> settings</button>
        </form>
    </div>
</div>
