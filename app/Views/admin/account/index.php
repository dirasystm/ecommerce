<?php
use Waimart\Services\AuthEvents;

/**
 * @var array<string,mixed> $user @var bool $mfaOn @var bool $required @var string|null $setupSecret @var string|null $setupUri
 * @var int $recoveryLeft @var list<string>|null $newCodes @var list<array<string,mixed>> $signIns @var list<string> $roles
 */
$mustChange = (int) $user['must_change_password'] === 1;
?>
<div class="wm-admin-head"><h1>My account</h1></div>
<p class="small text-muted"><?= e($user['first_name'] . ' ' . $user['last_name']) ?> · <?= e($user['email']) ?> · <?= $roles ? e(implode(', ', $roles)) : 'No role' ?></p>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="wm-panel mb-3" id="password">
            <div class="wm-panel-head">Password</div>
            <div class="wm-panel-body">
                <?php if ($mustChange): ?><div class="alert alert-warning small">Please choose a new password before you continue to the admin.</div><?php endif; ?>
                <form class="row g-3" method="post" action="<?= e(url('/admin/account/password')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="text" name="username" value="<?= e($user['email']) ?>" autocomplete="username" hidden>
                    <div class="col-12">
                        <label class="form-label" for="current_password">Current password</label>
                        <input class="form-control<?= invalid('current_password') ?>" type="password" id="current_password" name="current_password" autocomplete="current-password">
                        <?= field_error('current_password') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password">New password</label>
                        <input class="form-control<?= invalid('password') ?>" type="password" id="password" name="password" autocomplete="new-password" aria-describedby="pwHelp">
                        <div class="form-text" id="pwHelp">At least 8 characters, with a letter and a number.</div>
                        <?= field_error('password') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password_confirmation">New password again</label>
                        <input class="form-control<?= invalid('password_confirmation') ?>" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
                        <?= field_error('password_confirmation') ?>
                    </div>
                    <div class="col-12"><button class="btn btn-wm-green" type="submit">Change password</button></div>
                </form>
            </div>
        </section>

        <section class="wm-panel mb-3" id="two-step">
            <div class="wm-panel-head">Two-step sign-in</div>
            <div class="wm-panel-body">
                <?php if ($newCodes): ?>
                    <div class="alert alert-warning">
                        <p class="fw-semibold mb-2">Your recovery codes: save them somewhere safe now. Each works once, if you lose your phone. They will not be shown again.</p>
                        <ul class="list-unstyled font-monospace row row-cols-2 mb-0"><?php foreach ($newCodes as $c): ?><li class="col"><?= e($c) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <?php if ($mfaOn): ?>
                    <p><span class="badge badge-soft-success">On</span> since <?= e(date('d M Y', strtotime((string) $user['mfa_enabled_at']))) ?>.
                        After your password, you enter a code from your authenticator app. Recovery codes left: <strong><?= $recoveryLeft ?></strong>.</p>
                    <div class="row g-3">
                        <form class="col-md-6" method="post" action="<?= e(url('/admin/account/two-step/recovery-codes')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <label class="form-label small" for="rc_password">Password</label>
                            <input class="form-control form-control-sm mb-2<?= invalid('password') ?>" type="password" id="rc_password" name="password" autocomplete="current-password">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">New recovery codes</button>
                        </form>
                        <?php if (!$required): ?>
                            <form class="col-md-6" method="post" action="<?= e(url('/admin/account/two-step/disable')) ?>" data-confirm="Turn off two-step sign-in? Your account will be protected by your password only." novalidate>
                                <?= csrf_field() ?>
                                <label class="form-label small" for="off_password">Password</label>
                                <input class="form-control form-control-sm mb-2" type="password" id="off_password" name="password" autocomplete="current-password">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Turn off</button>
                            </form>
                        <?php else: ?>
                            <p class="col-md-6 small text-muted">Required for staff accounts (Settings → Security), so it cannot be turned off.</p>
                        <?php endif; ?>
                    </div>
                <?php elseif ($setupSecret !== null): ?>
                    <ol class="small">
                        <li>Open an authenticator app on your phone (for example Google Authenticator, Microsoft Authenticator or Authy) and add an account.</li>
                        <li>Enter this key (or, on your phone, <a href="<?= e((string) $setupUri) ?>">tap here to add it</a>):
                            <div class="font-monospace fs-5 my-2 user-select-all" aria-label="Setup key"><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></div>
                            Choose "time-based" if asked. Keep this key private.</li>
                        <li>Enter the 6-digit code the app shows, and your password.</li>
                    </ol>
                    <form class="row g-2 align-items-end" method="post" action="<?= e(url('/admin/account/two-step')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-sm-4">
                            <label class="form-label small" for="code">Code from the app</label>
                            <input class="form-control<?= invalid('code') ?>" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7">
                            <?= field_error('code') ?>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small" for="mfa_password">Password</label>
                            <input class="form-control<?= invalid('password') ?>" type="password" id="mfa_password" name="password" autocomplete="current-password">
                            <?= field_error('password') ?>
                        </div>
                        <div class="col-sm-3"><button class="btn btn-wm-green w-100" type="submit">Turn on</button></div>
                    </form>
                    <form class="mt-2" method="post" action="<?= e(url('/admin/account/two-step/cancel')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link px-0" type="submit">Cancel</button></form>
                <?php else: ?>
                    <p><span class="badge <?= $required ? 'badge-soft-danger' : 'badge-soft-muted' ?>">Off</span>
                        <?= $required ? 'Two-step sign-in is required for staff accounts. Set it up to continue.' : 'Recommended: even if someone learns your password, they cannot sign in without your phone.' ?></p>
                    <form method="post" action="<?= e(url('/admin/account/two-step/start')) ?>"><?= csrf_field() ?><button class="btn btn-wm-green" type="submit">Set up two-step sign-in</button></form>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Sessions</div>
            <div class="wm-panel-body small">
                <p>Last sign-in: <?= $user['last_login_at'] ? e(date('d M Y H:i', strtotime((string) $user['last_login_at']))) : '—' ?>.
                    Signed in on another device or shared computer? Sign it out from here.</p>
                <form method="post" action="<?= e(url('/admin/account/sessions')) ?>" data-confirm="Sign out of every other device? This one stays signed in.">
                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Sign out my other sessions</button>
                </form>
            </div>
        </section>
        <section class="wm-panel">
            <div class="wm-panel-head">Recent sign-in activity</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($signIns as $ev): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span><?= e(AuthEvents::LABELS[$ev['event']] ?? $ev['event']) ?><span class="d-block text-muted"><?= e((string) $ev['ip_address']) ?></span></span>
                        <span class="text-nowrap text-muted"><?= e(date('d M H:i', strtotime((string) $ev['created_at']))) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$signIns): ?><li class="list-group-item text-muted">Nothing yet.</li><?php endif; ?>
            </ul>
        </section>
    </div>
</div>
