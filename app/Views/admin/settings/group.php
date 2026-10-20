<?php
use Waimart\Core\Validator;
use Waimart\Services\SellerDocumentPolicy;
use Waimart\Services\SettingsService;

/**
 * @var string $group @var array<string,string> $values @var array<string,string> $docModes
 * @var array<string,mixed>|null $secret SMTP password status (email group) @var array<string,mixed>|null $mail effective mail settings
 */
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
                                <?php if ($widget === 'select'): ?>
                                    <select class="form-select<?= invalid($key) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>"<?= $helpId ? ' aria-describedby="' . e($helpId) . '"' : '' ?>>
                                        <?php foreach (SettingsService::OPTIONS[$key] as $opt => $optLabel): ?>
                                            <option value="<?= e((string) $opt) ?>" <?= $val === (string) $opt ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($widget === 'textarea'): ?>
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

        <?php if ($group === 'email' && $secret !== null && $mail !== null): ?>
            <section class="wm-panel mt-3">
                <div class="wm-panel-head">SMTP password</div>
                <div class="wm-panel-body">
                    <p class="small mb-2">
                        <?php if (!$secret['set']): ?>
                            <span class="badge badge-soft-muted">Not set</span>
                        <?php elseif (!$secret['readable']): ?>
                            <span class="badge badge-soft-danger">Cannot be read</span> The app key changed since it was saved. Enter the password again.
                        <?php else: ?>
                            <span class="badge badge-soft-success">Saved</span> <?= e(date('d M Y H:i', strtotime((string) $secret['updated_at']))) ?><?= $secret['updated_by'] ? ' by ' . e($secret['updated_by']) : '' ?>.
                        <?php endif; ?>
                        Stored encrypted and never shown again. Only staff allowed to manage credentials can change it.
                    </p>
                    <?php if (\Waimart\Core\Auth::can('secrets.manage')): ?>
                        <form class="row g-2 align-items-end" method="post" action="<?= e(url('/admin/settings/email/password')) ?>" autocomplete="off" novalidate>
                            <?= csrf_field() ?>
                            <div class="col-md-7">
                                <label class="form-label" for="secret_smtp_password"><?= $secret['set'] ? 'Replace the password' : 'Password' ?></label>
                                <input class="form-control<?= invalid('secret_smtp_password') ?>" type="password" id="secret_smtp_password" name="secret_smtp_password" maxlength="500" autocomplete="new-password">
                                <?= field_error('secret_smtp_password') ?>
                            </div>
                            <div class="col-md-5"><button class="btn btn-wm-green" type="submit">Save password</button></div>
                        </form>
                        <?php if ($secret['set']): ?>
                            <form class="mt-2" method="post" action="<?= e(url('/admin/settings/email/password')) ?>" data-confirm="Remove the saved SMTP password? Email sending through the mail server will stop working until a new one is saved.">
                                <?= csrf_field() ?><input type="hidden" name="remove" value="1">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Remove the saved password</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
            <section class="wm-panel mt-3">
                <div class="wm-panel-head">Sending now</div>
                <div class="wm-panel-body small">
                    <dl class="row mb-2">
                        <dt class="col-sm-4 fw-normal text-muted">Email</dt>
                        <dd class="col-sm-8 mb-1"><?= $mail['driver'] === 'smtp' ? 'Sent through ' . e($mail['host'] ?: '(no server set)') . ':' . (int) $mail['port'] : 'Not sent: written to the mail log (storage/logs)' ?>
                            <span class="text-muted">· <?= $mail['source'] === 'settings' ? 'from this page' : 'from the server configuration' ?></span></dd>
                        <dt class="col-sm-4 fw-normal text-muted">From</dt>
                        <dd class="col-sm-8 mb-1"><?= e($mail['from_name']) ?> &lt;<?= e($mail['from_email'] ?: 'not set') ?>&gt;</dd>
                        <dt class="col-sm-4 fw-normal text-muted">Replies go to</dt>
                        <dd class="col-sm-8 mb-1"><?= e($mail['reply_to'] ?: '—') ?></dd>
                        <dt class="col-sm-4 fw-normal text-muted">SMS</dt>
                        <dd class="col-sm-8 mb-1"><?= \Waimart\Services\SmsGateway::enabled() ? 'Sent through Talksasa bulk SMS' : 'Switched off: SMS are kept in the message log and not delivered to phones' ?>.
                            <?php if (\Waimart\Core\Auth::can('messaging.manage')): ?><a href="<?= e(url('/admin/messaging')) ?>">SMS gateway, balance and message log</a><?php endif; ?></dd>
                    </dl>
                    <form method="post" action="<?= e(url('/admin/settings/email/test')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Send me a test email</button>
                        <span class="text-muted ms-1">Uses the saved settings; save first.</span>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
