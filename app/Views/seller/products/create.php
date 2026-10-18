<?php
use Waimart\Core\View;

$sellerMode = true;
?>
<div class="wm-admin-head">
    <h1>Add a product</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/seller/products')) ?>">Back</a>
</div>
<form method="post" action="<?= e(url('/seller/products')) ?>" class="row g-3" novalidate>
    <?= csrf_field() ?>
    <div class="col-xl-8">
        <div class="wm-panel">
            <div class="wm-panel-head">Product details</div>
            <div class="wm-panel-body"><?= View::partial('admin/products/_fields', get_defined_vars()) ?></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="wm-panel">
            <div class="wm-panel-head">Price and stock</div>
            <div class="wm-panel-body">
                <p class="small text-muted">Every product is sold as at least one option. You can add sizes, colours or other options after saving. The SKU is your own product code.</p>
                <?= View::partial('admin/products/_variant_fields', ['v' => null, 'p' => 'new', 'withStock' => true]) ?>
            </div>
        </div>
        <div class="d-grid mt-3"><button class="btn btn-wm-green btn-lg" type="submit">Save as draft</button></div>
        <p class="small text-muted mt-2">Next you will add photos and submit the product for approval.</p>
    </div>
</form>
