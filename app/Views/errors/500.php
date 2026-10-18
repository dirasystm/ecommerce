<h1>Something went wrong</h1>
<p>We hit an unexpected problem. Please try again in a moment.</p>
<?php if (!empty($message)): ?>
    <p><small><code><?= e($message) ?></code></small></p>
<?php endif; ?>
<a class="btn" href="<?= e(url('/')) ?>">Go to homepage</a>
