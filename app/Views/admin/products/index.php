<?php
use Waimart\Core\View;

use Waimart\Services\SellerProductService;

/** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $categories @var list<array<string,mixed>> $sellers */
$req = View::shared('request');
$path = '/admin/products';
$cat = $req->query['category'] ?? '';
$status = $req->query['status'] ?? '';
$sellerQ = $req->query['seller'] ?? '';
$moderationQ = $req->query['moderation'] ?? '';
?>
<div class="wm-admin-head">
    <h1>Products</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/products/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New product</a>
</div>

<?php
$opt = function (array $rows, string $all): array {
    $o = ['' => $all];
    foreach ($rows as $r) {
        $o[(string) $r['id']] = (string) $r['name'];
    }
    return $o;
};
$modOpts = ['' => 'Any approval state'];
foreach (SellerProductService::STATUS_LABELS as $k => [, $l]) {
    $modOpts[$k] = $l;
}
?>
<?= View::partial('partials/table/toolbar', ['id' => 'products', 'path' => $path, 'lp' => $lp, 'label' => 'Search products', 'placeholder' => 'Product name or SKU',
    'filters' => [
        ['name' => 'seller', 'label' => 'Seller', 'value' => (string) $sellerQ, 'options' => $opt($sellers, 'All sellers')],
        ['name' => 'category', 'label' => 'Category', 'value' => (string) $cat, 'options' => $opt($categories, 'All categories')],
        ['name' => 'status', 'label' => 'Status', 'value' => (string) $status, 'options' => ['' => 'Active and inactive', 'active' => 'Active', 'inactive' => 'Inactive']],
        ['name' => 'moderation', 'label' => 'Approval', 'value' => (string) $moderationQ, 'options' => $modOpts],
    ]]) ?>

<div class="wm-panel">
    <div id="products" class="wm-table-region" data-table-region data-loading-text="Loading products…">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <caption class="visually-hidden">Products</caption>
            <thead><tr><th scope="col"><span class="visually-hidden">Image</span></th><?= sort_th('Product', 'name', $lp, $path) ?><th scope="col">Seller</th><th scope="col">Category</th><th scope="col">Brand</th><?= sort_th('From', 'price', $lp, $path, 'text-end') ?><th scope="col" class="text-end">Variants</th><?= sort_th('Available', 'available', $lp, $path, 'text-end') ?><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $p): ?>
                <tr>
                    <td><img class="wm-thumb-sm" src="<?= e(media($p['image'])) ?>" alt="" width="44" height="44" loading="lazy"></td>
                    <td><a class="fw-semibold" href="<?= e(url('/admin/products/' . $p['id'])) ?>"><?= e($p['name']) ?></a><?= (int) $p['is_featured'] ? ' <span class="badge badge-soft-warning">Featured</span>' : '' ?></td>
                    <td class="small"><?= e($p['seller_name']) ?><?= $p['seller_status'] !== 'active' ? ' <span class="badge badge-soft-danger">Suspended</span>' : '' ?></td>
                    <td class="small"><?= e($p['category']) ?></td>
                    <td class="small"><?= e($p['brand'] ?? '—') ?></td>
                    <td class="text-end text-nowrap"><?= $p['min_price'] !== null ? e(money($p['min_price'])) : '—' ?></td>
                    <td class="text-end"><?= (int) $p['variants'] ?></td>
                    <td class="text-end"><?= (int) $p['available'] <= 0 ? '<span class="badge badge-soft-danger">0</span>' : (int) $p['available'] ?></td>
                    <td><?= (int) $p['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-muted">Inactive</span>' ?>
                        <?= $p['moderation_status'] !== 'published' ? SellerProductService::badge((string) $p['moderation_status']) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><?= empty_row(9, true, 'No products yet.', 'No products found. Try changing your search or filters.') ?><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/products', 'id' => 'products', 'noun' => 'products']) ?>
    </div>
</div>
