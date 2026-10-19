<?php
/**
 * @var array<string,mixed> $t the template @var array<string,string> $vars placeholders
 * @var array<string,list<string>> $required @var array{subject:?string,email:?string,sms:?string} $preview
 */
$f = fn (string $k) => (string) fv($k, $t[$k]);
$hasEmail = in_array('email', $t['channels'], true);
$hasSms = in_array('sms', $t['channels'], true);
$req = fn (string $c) => $required[$c] ?? [];
?>
<div class="wm-admin-head"><h1><?= e($t['name']) ?></h1></div>
<p><a href="<?= e(url('/admin/messages')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Messages</a></p>
<?php if ($t['help']): ?><p class="small text-muted"><?= e($t['help']) ?></p><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <form class="wm-panel" method="post" action="<?= e(url('/admin/messages/' . $t['code'])) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="wm-panel-body row g-3">
                <?php if ($hasEmail): ?>
                    <div class="col-12">
                        <?php if (in_array('email', $t['locked'], true)): ?>
                            <p class="small mb-0"><span class="badge badge-soft-success">Email always sent</span></p>
                        <?php else: ?>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled" value="1" <?= fchecked('email_enabled', (bool) $t['email_enabled']) ?>>
                                <label class="form-check-label" for="email_enabled">Send this email</label></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="email_subject">Email subject</label>
                        <input class="form-control<?= invalid('email_subject') ?>" id="email_subject" name="email_subject" maxlength="190" value="<?= e($f('email_subject')) ?>">
                        <?= field_error('email_subject') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="email_body">Email text</label>
                        <textarea class="form-control font-monospace small<?= invalid('email_body') ?>" id="email_body" name="email_body" rows="10" maxlength="5000"<?= $req('email') ? ' aria-describedby="emailReq"' : '' ?>><?= e($f('email_body')) ?></textarea>
                        <?php if ($req('email')): ?><div class="form-text" id="emailReq">Must include <code>{<?= e(implode('}</code>, <code>{', $req('email'))) ?>}</code>.</div><?php endif; ?>
                        <?= field_error('email_body') ?>
                    </div>
                <?php endif; ?>
                <?php if ($hasSms): ?>
                    <div class="col-12">
                        <?php if (in_array('sms', $t['locked'], true)): ?>
                            <p class="small mb-0"><span class="badge badge-soft-success">SMS always sent</span></p>
                        <?php else: ?>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="sms_enabled" name="sms_enabled" value="1" <?= fchecked('sms_enabled', (bool) $t['sms_enabled']) ?>>
                                <label class="form-check-label" for="sms_enabled">Send this SMS</label></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="sms_body">SMS text</label>
                        <textarea class="form-control font-monospace small<?= invalid('sms_body') ?>" id="sms_body" name="sms_body" rows="3" maxlength="480" aria-describedby="smsHelp"><?= e($f('sms_body')) ?></textarea>
                        <div class="form-text" id="smsHelp">One line. 160 characters make one SMS; longer messages are sent in parts.<?= $req('sms') ? ' Must include <code>{' . e(implode('}</code>, <code>{', $req('sms'))) . '}</code>.' : '' ?></div>
                        <?= field_error('sms_body') ?>
                    </div>
                <?php endif; ?>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button class="btn btn-wm-green" type="submit">Save message</button>
                </div>
            </div>
        </form>
        <?php if ($t['edited']): ?>
            <form class="mt-2" method="post" action="<?= e(url('/admin/messages/' . $t['code'] . '/restore')) ?>" data-confirm="Go back to the original wording? Your edits to this message will be lost.">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore the original wording</button>
                <span class="small text-muted ms-2">Last edited <?= e(date('d M Y H:i', strtotime((string) $t['updated_at']))) ?><?= $t['updated_by'] ? ' by ' . e($t['updated_by']) : '' ?></span>
            </form>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Placeholders</div>
            <div class="wm-panel-body">
                <dl class="row small mb-0">
                    <?php foreach ($vars as $name => $desc): ?>
                        <dt class="col-5 fw-normal"><code>{<?= e($name) ?>}</code></dt><dd class="col-7 mb-1"><?= e($desc) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </section>
        <section class="wm-panel">
            <div class="wm-panel-head">Preview (saved wording, sample order)</div>
            <div class="wm-panel-body small">
                <?php if ($preview['email'] !== null): ?>
                    <p class="fw-semibold mb-1"><?= e((string) $preview['subject']) ?></p>
                    <pre class="wm-message-preview"><?= e($preview['email']) ?></pre>
                <?php elseif ($hasEmail): ?><p class="text-muted">Email switched off.</p><?php endif; ?>
                <?php if ($preview['sms'] !== null): ?>
                    <p class="fw-semibold mb-1">SMS (<?= mb_strlen($preview['sms']) ?> characters)</p>
                    <pre class="wm-message-preview mb-0"><?= e($preview['sms']) ?></pre>
                <?php elseif ($hasSms): ?><p class="text-muted mb-0">SMS switched off.</p><?php endif; ?>
            </div>
        </section>
    </div>
</div>
