<?php
use Waimart\Core\View;

use Waimart\Services\SellerProductService;

/** @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $pages @var list<array<string,mixed>> $categories @var list<array<string,mixed>> $sellers */
$req = View::shared('request');
$q = $req->query['q'] ?? '';
$cat = $req->query['category'] ?? '';
$status = $req->query['status'] ?? '';
$sellerQ = $req->query['seller'] ?? '';
$moderationQ = $req->query['moderation'] ?? '';
?>
<div class="wm-admin-head">
    <h1>Products <span class="text-muted fs-6 fw-normal">(<?= number_format($total) ?>)</span></h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/products/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New product</a>
</div>

<form class="wm-panel wm-panel-body mb-3 row g-2 align-items-end" method="get" action="<?= e(url('/admin/products')) ?>">
    <div class="col-md-3">
        <label class="form-label small" for="q">Search name or SKU</label>
        <input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="100">
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="seller">Seller</label>
        <select class="form-select" id="seller" name="seller">
            <option value="">All</option>
            <?php foreach ($sellers as $sl): ?><option value="<?= $sl['id'] ?>" <?= (string) $sellerQ === (string) $sl['id'] ? 'selected' : '' ?>><?= e($sl['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="category">Category</label>
        <select class="form-select" id="category" name="category">
            <option value="">All</option>
            <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= (string) $cat === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">All</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="moderation">Approval</label>
        <select class="form-select" id="moderation" name="moderation">
            <option value="">All</option>
            <?php foreach (SellerProductService::STATUS_LABELS as $k => [, $l]): ?><option value="<?= $k ?>" <?= $moderationQ === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-1 d-grid"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
</form>

<div class="wm-panel">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th></th><th>Product</th><th>Seller</th><th>Category</th><th>Brand</th><th class="text-end">From</th><th class="text-end">Variants</th><th class="text-end">Available</th><th>Status</th></tr></thead>
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
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No products found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/products']) ?>
</div>
