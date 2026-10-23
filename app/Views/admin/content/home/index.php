<?php
use Waimart\Services\ContentRenderer;
use Waimart\Services\SiteContentService;

/** @var list<array<string,mixed>> $rows */
$now = time();
?>
<div class="wm-admin-head">
    <h1>Homepage</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('/admin/content/homepage/new', ['type' => 'category_row'])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Category row</a>
        <a class="btn btn-outline-secondary" href="<?= e(url('/admin/content/homepage/new', ['type' => 'text'])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Text block</a>
    </div>
</div>
<p class="small text-muted">The homepage, top to bottom. Move sections, rename them, change how many products they show, hide them or schedule them. Slides are edited under <a href="<?= e(url('/admin/banners')) ?>">Banners</a>; the side tiles and "why shop with us" items under <a href="<?= e(url('/admin/content/menus')) ?>">Menus</a>.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" data-wm-table="10" data-wm-noun="sections">
        <thead><tr><th scope="col" data-no-sort>Order</th><th scope="col">Section</th><th scope="col">Shows</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s):
            $live = (int) $s['is_visible'] && (!$s['starts_at'] || strtotime((string) $s['starts_at']) <= $now) && (!$s['ends_at'] || strtotime((string) $s['ends_at']) > $now);
            $status = !(int) $s['is_visible'] ? 'Hidden' : ($live ? 'Showing' : ($s['starts_at'] && strtotime((string) $s['starts_at']) > $now ? 'From ' . date('d M H:i', strtotime((string) $s['starts_at'])) : 'Ended')); ?>
            <tr class="<?= $live ? '' : 'text-muted' ?>">
                <td class="text-nowrap">
                    <?php foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $dir => $icon): ?>
                        <form class="d-inline" method="post" action="<?= e(url('/admin/content/homepage/' . $s['id'] . '/move')) ?>"><?= csrf_field() ?>
                            <input type="hidden" name="direction" value="<?= $dir ?>">
                            <button class="btn btn-sm btn-link p-0 me-1" type="submit" aria-label="Move <?= e(ContentRenderer::plain((string) $s['title'])) ?> <?= $dir ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></button></form>
                    <?php endforeach; ?>
                </td>
                <td class="fw-semibold"><?= e(ContentRenderer::plain((string) $s['title'])) ?></td>
                <td class="small"><?= e(SiteContentService::SECTION_TYPES[$s['type']]) ?><?= $s['category_name'] ? ': ' . e($s['category_name']) : '' ?><?= $s['item_limit'] ? ' · up to ' . (int) $s['item_limit'] : '' ?></td>
                <td><span class="badge <?= $live ? 'badge-soft-success' : 'badge-soft-muted' ?>"><?= e($status) ?></span></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/content/homepage/' . $s['id'])) ?>">Edit</a>
                    <?php if (in_array($s['type'], SiteContentService::REPEATABLE_SECTIONS, true)): ?>
                        <form class="d-inline" method="post" action="<?= e(url('/admin/content/homepage/' . $s['id'] . '/delete')) ?>" data-confirm="Remove this section from the homepage?"><?= csrf_field() ?>
                            <button class="btn btn-sm btn-link text-danger" type="submit">Remove</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<p class="small text-muted mt-2"><a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">View the homepage <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a></p>
