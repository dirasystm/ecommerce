<?php /** @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $rows */ ?>
<div class="wm-admin-head">
    <h1>Home page banners</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/banners/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New banner</a>
</div>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'banners', 'path' => '/admin/banners', 'lp' => $lp, 'label' => 'Search banners', 'placeholder' => 'Title, subtitle or link']) ?>
<div class="wm-panel">
    <div id="banners" class="wm-table-region" data-table-region data-loading-text="Loading banners…">
        <div class="table-responsive">
    <table class="table table-hover mb-0">
        <caption class="visually-hidden">Home page banners</caption>
        <thead><tr><th scope="col"></th><th scope="col">Title</th><th scope="col">Link</th><th scope="col">Schedule</th><th scope="col" class="text-end">Order</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $b): $live = (int) $b['is_active'] && (!$b['starts_at'] || strtotime($b['starts_at']) <= time()) && (!$b['ends_at'] || strtotime($b['ends_at']) > time()); ?>
            <tr>
                <td><?php if ($b['image_path']): ?><img class="wm-thumb-sm" src="<?= e(media($b['image_path'])) ?>" alt=""><?php else: ?><span class="badge badge-soft-muted">Text</span><?php endif; ?></td>
                <td class="fw-semibold"><?= e($b['title']) ?><div class="small text-muted fw-normal"><?= e((string) $b['subtitle']) ?></div></td>
                <td class="small"><code><?= e((string) $b['link_url']) ?></code></td>
                <td class="small text-nowrap"><?= $b['starts_at'] ? e(date('d M H:i', strtotime($b['starts_at']))) : 'Now' ?> → <?= $b['ends_at'] ? e(date('d M H:i', strtotime($b['ends_at']))) : 'No end' ?></td>
                <td class="text-end"><?= (int) $b['sort_order'] ?></td>
                <td><?= $live ? '<span class="badge badge-soft-success">Live</span>' : ((int) $b['is_active'] ? '<span class="badge badge-soft-warning">Scheduled</span>' : '<span class="badge badge-soft-muted">Off</span>') ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/banners/' . $b['id'])) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(7, $lp->q !== '', 'No banners. The home page shows a welcome slide.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
        <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/banners', 'id' => 'banners', 'noun' => 'banners']) ?>
    </div>
</div>
