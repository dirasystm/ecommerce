<?php
$map = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
foreach ($map as $key => $class):
    $msg = flash($key);
    if ($msg === null || $msg === '') {
        continue;
    } ?>
    <div class="alert alert-<?= $class ?> alert-dismissible fade show wm-flash" role="<?= $key === 'error' ? 'alert' : 'status' ?>">
        <?= e($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
    </div>
<?php endforeach; ?>
