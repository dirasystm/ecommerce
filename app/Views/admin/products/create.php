<?php
use Waimart\Core\View;
?>
<div class="wm-admin-head">
    <h1>New product</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/products')) ?>">Back</a>
</div>
<form method="post" action="<?= e(url('/admin/products')) ?>" class="row g-3">
    <?= csrf_field() ?>
    <div class="col-xl-8">
        <div class="wm-panel">
            <div class="wm-panel-head">Product details</div>
            <div class="wm-panel-body"><?= View::partial('admin/products/_fields', get_defined_vars()) ?></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="wm-panel">
            <div class="wm-panel-head">Default variant</div>
            <div class="wm-panel-body">
                <p class="small text-muted">Every product is sold as at least one variant. Add sizes, colours or storage options after saving.</p>
                <?= View::partial('admin/products/_variant_fields', ['v' => null, 'p' => 'new', 'withStock' => true]) ?>
                <p class="small text-muted mt-2 mb-0">Opening stock is recorded in the stock ledger under your name.</p>
            </div>
        </div>
        <div class="d-grid mt-3"><button class="btn btn-wm-green btn-lg" type="submit">Create product</button></div>
    </div>
</form>
