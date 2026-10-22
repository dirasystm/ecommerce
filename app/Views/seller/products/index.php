<?php
use Waimart\Services\SellerContext;
use Waimart\Services\SellerProductService;

/** @var \Waimart\Core\ListParams $lp @var ?string $status @var list<array<string,mixed>> $rows @var array<string,int> $counts @var bool $moderated @var array<string,mixed> $seller */
$canEdit = in_array(SellerContext::role(), ['owner', 'manager'], true) && $seller['status'] === 'active';
?>
<div class="wm-admin-head">
    <h1>My products <span class="text-muted fs-6 fw-normal">(<?= (int) ($counts['all'] ?? 0) ?>)</span></h1>
    <?php if ($canEdit): ?><a class="btn btn-wm-green" href="<?= e(url('/seller/products/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add a product</a><?php endif; ?>
</div>
<ul class="nav nav-pills mb-3 small">
    <li class="nav-item"><a class="nav-link<?= $status === null ? ' active' : '' ?>" href="<?= e(url('/seller/products')) ?>">All</a></li>
    <?php foreach (SellerProductService::STATUS_LABELS as $k => [, $l]): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/seller/products', ['status' => $k])) ?>"><?= e($l) ?> <span class="badge text-bg-light"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>
<?php if (($counts['low_stock'] ?? 0) > 0): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= (int) $counts['low_stock'] ?> product(s) are low on stock or sold out.</div>
<?php endif; ?>
<p class="small text-muted"><?= $moderated
    ? 'New products, and changes to a product\'s name, description, category or photos, are checked by ' . brand_name() . ' before they appear in the shop. Price and stock changes apply immediately.'
    : 'Products are published as soon as you submit them.' ?></p>

<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'sproducts', 'path' => '/seller/products', 'lp' => $lp, 'label' => 'Search your products', 'placeholder' => 'Product name, SKU or category', 'keep' => ['status' => (string) $status]]) ?>
<div class="wm-panel"><div id="sproducts" class="wm-table-region" data-table-region data-loading-text="Loading products…"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Your products</caption>
        <thead><tr><th scope="col"><span class="visually-hidden">Image</span></th><?= sort_th('Product', 'name', $lp, '/seller/products') ?><th scope="col">Category</th><th scope="col" class="text-end">From</th><th scope="col" class="text-end">Options</th><?= sort_th('Available', 'available', $lp, '/seller/products', 'text-end') ?><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><img class="wm-thumb-sm" src="<?= e(media($p['image'])) ?>" alt="" width="44" height="44" loading="lazy"></td>
                <td><a class="fw-semibold" href="<?= e(url('/seller/products/' . $p['id'])) ?>"><?= e($p['name']) ?></a>
                    <?php if ($p['moderation_status'] === 'rejected' && $p['moderation_note']): ?><div class="small text-danger"><?= e($p['moderation_note']) ?></div><?php endif; ?></td>
                <td class="small"><?= e($p['category']) ?></td>
                <td class="text-end text-nowrap"><?= $p['min_price'] !== null ? e(money($p['min_price'])) : '—' ?></td>
                <td class="text-end"><?= (int) $p['variants'] ?></td>
                <td class="text-end"><?= (int) $p['available'] <= 0 ? '<span class="badge badge-soft-danger">0</span>' : (int) $p['available'] ?>
                    <?= (int) $p['low_variants'] > 0 && (int) $p['available'] > 0 ? '<span class="badge badge-soft-warning">Low</span>' : '' ?></td>
                <td><?= SellerProductService::badge((string) $p['moderation_status']) ?><?= (int) $p['is_active'] ? '' : ' <span class="badge badge-soft-muted">Unlisted</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', 'No products here yet.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/seller/products', 'id' => 'sproducts', 'noun' => 'products']) ?>
</div></div>
