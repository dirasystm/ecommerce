<?php
use Waimart\Services\SellerProductService;

/** @var string $status @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp */
?>
<div class="wm-admin-head"><h1>Product approvals</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['pending' => 'Waiting', 'rejected' => 'Rejected', 'published' => 'Published'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/moderation/products', ['status' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">Seller products appear in the shop only once approved (while the setting "Require approval before seller products go live" is on). Open a product to check its photos, description and options before deciding.</p>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'approvals', 'path' => '/admin/moderation/products', 'lp' => $lp, 'label' => 'Search products to approve',
    'placeholder' => 'Product, seller or category', 'keep' => ['status' => $status]]) ?>
<div class="wm-panel"><div id="approvals" class="wm-table-region" data-table-region data-loading-text="Loading products…"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <caption class="visually-hidden">Product approvals</caption>
        <thead><tr><th scope="col"><span class="visually-hidden">Image</span></th><th scope="col">Product</th><th scope="col">Seller</th><th scope="col">Category</th><th scope="col" class="text-end">From</th><th scope="col"><?= $status === 'pending' ? 'Submitted' : 'Decided' ?></th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><img class="wm-thumb-sm" src="<?= e(media($p['image'])) ?>" alt="" width="44" height="44" loading="lazy"></td>
                <td><a class="fw-semibold" href="<?= e(url('/admin/products/' . $p['id'])) ?>"><?= e($p['name']) ?></a>
                    <?php if ($p['moderation_note']): ?><div class="small text-muted"><?= e($p['moderation_note']) ?></div><?php endif; ?></td>
                <td class="small"><?= e($p['seller_name']) ?><?= $p['seller_status'] !== 'active' ? ' <span class="badge badge-soft-danger">Suspended</span>' : '' ?></td>
                <td class="small"><?= e($p['category']) ?></td>
                <td class="text-end text-nowrap"><?= $p['min_price'] !== null ? e(money($p['min_price'])) : '—' ?></td>
                <td class="small text-nowrap"><?php $d = $status === 'pending' ? $p['submitted_at'] : $p['moderated_at']; echo $d ? e(date('d M Y H:i', strtotime($d))) : '—'; ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($status === 'pending'): ?>
                        <form class="d-inline" method="post" action="<?= e(url('/admin/moderation/products/' . $p['id'] . '/approve')) ?>">
                            <?= csrf_field() ?><button class="btn btn-sm btn-wm-green" type="submit">Approve</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/products/' . $p['id'])) ?>">Review</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', $status === 'pending' ? 'No products waiting for approval.' : 'Nothing here.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
<?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/moderation/products', 'id' => 'approvals', 'noun' => 'products']) ?>
</div></div>
<p class="small text-muted mt-2"><?= SellerProductService::badge('pending') ?> products are hidden from customers until approved.</p>
