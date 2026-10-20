<h1>Staff sign in</h1>
<?php if ($msg = flash('error')): ?>
    <p role="alert" style="color:#c62828;font-weight:600"><?= e($msg) ?></p>
<?php endif; ?>
<?php if ($msg = flash('success')): ?>
    <p role="status" style="color:#1b7a43;font-weight:600"><?= e($msg) ?></p>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/login')) ?>" style="text-align:left;max-width:360px;margin:16px auto 0">
    <?= csrf_field() ?>
    <label for="login" style="display:block;font-weight:600;margin-bottom:4px">Email or phone number</label>
    <input id="login" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= e((string) (old('login') ?: old('email'))) ?>"
           style="width:100%;padding:10px;border:1px solid #bbb;border-radius:4px;margin-bottom:12px">
    <label for="password" style="display:block;font-weight:600;margin-bottom:4px">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required
           style="width:100%;padding:10px;border:1px solid #bbb;border-radius:4px;margin-bottom:16px">
    <button type="submit" style="width:100%;padding:11px;background:var(--wm-action);color:#fff;border:0;border-radius:4px;font-weight:700;cursor:pointer">Sign in</button>
    <p style="text-align:center;margin:14px 0 0;font-size:.9rem"><a style="color:var(--wm-green,#005b3d);font-weight:600" href="<?= e(url('/forgot-password', ['staff' => 1])) ?>">Forgot your password?</a></p>
</form>
<p style="font-size:.85rem;color:#5f6368;margin-top:16px">Staff access only. Sign-in attempts are rate-limited and logged.</p>
