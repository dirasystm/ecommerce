<?php
use Waimart\Services\ContentRenderer;
use Waimart\Services\SiteContentService;

/** @var list<array<string,mixed>> $rows */
$now = time();
$state = function (array $p) use ($now): array {
    if ($p['status'] !== 'published') {
        return ['badge-soft-muted', 'Draft'];
    }
    if ($p['publish_at'] && strtotime((string) $p['publish_at']) > $now) {
        return ['badge-soft-warning', 'Scheduled ' . date('d M Y H:i', strtotime((string) $p['publish_at']))];
    }
    if ($p['unpublish_at'] && strtotime((string) $p['unpublish_at']) <= $now) {
        return ['badge-soft-muted', 'Ended'];
    }
    return ['badge-soft-success', 'Published'];
};
?>
<div class="wm-admin-head">
    <h1>Pages</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/content/pages/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New page</a>
</div>
<p class="small text-muted">Information and legal pages. Built-in pages keep their addresses and cannot be deleted. Every save keeps the previous version, so changes can be undone.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Page</th><th>Address</th><th>Status</th><th>Help menu</th><th>Last change</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): [$cls, $label] = $state($p); $href = SiteContentService::pageUrl((string) $p['slug']); ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(url('/admin/content/pages/' . $p['id'])) ?>"><?= e(ContentRenderer::plain((string) $p['title'])) ?></a>
                    <?= (int) $p['is_system'] ? '<span class="badge badge-soft-muted ms-1">built-in</span>' : '' ?></td>
                <td class="small"><code><?= e($href) ?></code></td>
                <td><span class="badge <?= $cls ?>"><?= e($label) ?></span></td>
                <td class="small"><?= (int) $p['show_in_nav'] ? 'Yes' : '—' ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', strtotime((string) $p['updated_at']))) ?><?= $p['editor'] ? ' · ' . e($p['editor']) : '' ?></td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url($href)) ?>" target="_blank" rel="noopener">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
