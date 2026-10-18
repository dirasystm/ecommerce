<?php
use Waimart\Services\SellerProductService;

/** @var string $status @var list<array<string,mixed>> $rows */
?>
<div class="wm-admin-head"><h1>Product approvals</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['pending' => 'Waiting', 'rejected' => 'Rejected', 'published' => 'Published'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/moderation/products', ['status' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">Seller products appear in the shop only once approved (while the setting "Require approval before seller products go live" is on). Open a product to check its photos, description and options before deciding.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th></th><th>Product</th><th>Seller</th><th>Category</th><th class="text-end">From</th><th><?= $status === 'pending' ? 'Submitted' : 'Decided' ?></th><th></th></tr></thead>
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
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing here.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<p class="small text-muted mt-2"><?= SellerProductService::badge('pending') ?> products are hidden from customers until approved.</p>
