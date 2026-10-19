<h1>Two-step sign-in</h1>
<?php if ($msg = flash('error')): ?>
    <p role="alert" style="color:#c62828;font-weight:600"><?= e($msg) ?></p>
<?php endif; ?>
<p style="max-width:360px;margin:8px auto 0">Open your authenticator app and enter the 6-digit code for <?= e(brand_name()) ?>.
    Lost your phone? Enter one of your recovery codes instead.</p>
<form method="post" action="<?= e(url('/admin/login/two-step')) ?>" style="text-align:left;max-width:360px;margin:16px auto 0" novalidate>
    <?= csrf_field() ?>
    <label for="code" style="display:block;font-weight:600;margin-bottom:4px">Code</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" required autofocus maxlength="20"
           <?= error('code') !== null ? 'aria-invalid="true" aria-describedby="codeError"' : '' ?>
           style="width:100%;padding:10px;border:1px solid #bbb;border-radius:4px;margin-bottom:6px;font-size:1.2rem;letter-spacing:.15em">
    <?php if (($err = error('code')) !== null): ?><p id="codeError" style="color:#c62828;font-size:.9rem;margin:0 0 10px"><?= e((string) $err) ?></p><?php endif; ?>
    <button type="submit" style="width:100%;padding:11px;background:var(--wm-action);color:#fff;border:0;border-radius:4px;font-weight:700;cursor:pointer;margin-top:8px">Verify and sign in</button>
</form>
<p style="font-size:.85rem;color:#5f6368;margin-top:16px"><a href="<?= e(url('/admin/login')) ?>">Start again</a> · Codes are checked once and attempts are logged.</p>
