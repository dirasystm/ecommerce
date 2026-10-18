<?php
/** @var string $group */
?>
<div class="wm-admin-head"><h1>Settings</h1></div>
<div class="row g-3">
    <div class="col-lg-3"><?= \Waimart\Core\View::partial('admin/settings/_nav', ['group' => $group]) ?></div>
    <div class="col-lg-9">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Export</div>
            <div class="wm-panel-body">
                <p class="small">Download the shop's settings as a file — to keep a record, or to copy the configuration to another installation (for example a test copy). M-Pesa payment numbers are left out, and no passwords or secret keys are ever included.</p>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/settings/export')) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download settings</a>
            </div>
        </section>
        <form class="wm-panel" method="post" action="<?= e(url('/admin/settings/import')) ?>" enctype="multipart/form-data" data-confirm="Import these settings? Matching settings will be replaced (the change is recorded in the audit log)." novalidate>
            <?= csrf_field() ?>
            <div class="wm-panel-head">Import</div>
            <div class="wm-panel-body">
                <p class="small">Apply a settings file exported from this page. Every value is checked exactly as on the settings pages; if any value is invalid, nothing is imported. M-Pesa payment numbers can never be imported — set them on the M-Pesa page.</p>
                <label class="form-label" for="file">Settings file (.json)</label>
                <input class="form-control<?= invalid('file') ?>" type="file" id="file" name="file" accept="application/json,.json">
                <?= field_error('file') ?>
                <button class="btn btn-wm-green mt-3" type="submit">Import settings</button>
            </div>
        </form>
    </div>
</div>
