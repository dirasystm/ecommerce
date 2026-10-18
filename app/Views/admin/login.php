<h1>Staff sign in</h1>
<?php if ($msg = flash('error')): ?>
    <p role="alert" style="color:#c62828;font-weight:600"><?= e($msg) ?></p>
<?php endif; ?>
<?php if ($msg = flash('success')): ?>
    <p role="status" style="color:#1b7a43;font-weight:600"><?= e($msg) ?></p>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/login')) ?>" style="text-align:left;max-width:360px;margin:16px auto 0">
    <?= csrf_field() ?>
    <label for="email" style="display:block;font-weight:600;margin-bottom:4px">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="<?= e(old('email')) ?>"
           style="width:100%;padding:10px;border:1px solid #bbb;border-radius:4px;margin-bottom:12px">
    <label for="password" style="display:block;font-weight:600;margin-bottom:4px">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required
           style="width:100%;padding:10px;border:1px solid #bbb;border-radius:4px;margin-bottom:16px">
    <button type="submit" style="width:100%;padding:11px;background:var(--wm-action);color:#fff;border:0;border-radius:4px;font-weight:700;cursor:pointer">Sign in</button>
</form>
<p style="font-size:.85rem;color:#5f6368;margin-top:16px">Staff access only. Sign-in attempts are rate-limited and logged.</p>
