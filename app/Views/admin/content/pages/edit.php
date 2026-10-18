<?php
use Waimart\Services\ContentRenderer;
use Waimart\Services\SiteContentService;

/** @var array<string,mixed>|null $p @var list<array<string,mixed>> $revisions */
$isNew = $p === null;
$system = !$isNew && (int) $p['is_system'] === 1;
$val = fn (string $k, string $d = '') => (string) fv($k, $p[$k] ?? $d);
$dt = fn (?string $v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
?>
<div class="wm-admin-head">
    <h1><?= $isNew ? 'New page' : 'Edit page' ?></h1>
    <?php if (!$isNew): ?><a class="btn btn-outline-secondary" href="<?= e(url(SiteContentService::pageUrl((string) $p['slug']))) ?>" target="_blank" rel="noopener"><i class="bi bi-eye" aria-hidden="true"></i> View<?= $p['status'] !== 'published' ? ' (preview)' : '' ?></a><?php endif; ?>
</div>
<p><a href="<?= e(url('/admin/content/pages')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Pages</a></p>
<div class="row g-3">
    <div class="col-lg-8">
        <form class="wm-panel" method="post" action="<?= e(url($isNew ? '/admin/content/pages/new' : '/admin/content/pages/' . $p['id'])) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="wm-panel-body row g-3">
                <div class="col-md-7">
                    <label class="form-label" for="title">Title</label>
                    <input class="form-control<?= invalid('title') ?>" id="title" name="title" maxlength="120" value="<?= e($val('title')) ?>">
                    <?= field_error('title') ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="slug">Address</label>
                    <?php if ($system): ?>
                        <input class="form-control" id="slug" value="<?= e(SiteContentService::pageUrl((string) $p['slug'])) ?>" disabled aria-describedby="slugHelp">
                        <div class="form-text" id="slugHelp">Built-in page: the address is fixed.</div>
                    <?php else: ?>
                        <div class="input-group"><span class="input-group-text">/pages/</span>
                            <input class="form-control<?= invalid('slug') ?>" id="slug" name="slug" maxlength="80" value="<?= e($val('slug')) ?>" placeholder="shipping-policy"></div>
                        <?= field_error('slug') ?>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="meta_description">Description for search engines <span class="text-muted fw-normal">(optional)</span></label>
                    <input class="form-control<?= invalid('meta_description') ?>" id="meta_description" name="meta_description" maxlength="200" value="<?= e($val('meta_description')) ?>">
                    <?= field_error('meta_description') ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="body">Content</label>
                    <textarea class="form-control font-monospace<?= invalid('body') ?>" id="body" name="body" rows="22" aria-describedby="formatHelp" style="font-size:.875rem"><?= e($val('body')) ?></textarea>
                    <?= field_error('body') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select<?= invalid('status') ?>" id="status" name="status">
                        <?php foreach (['published' => 'Published', 'draft' => 'Draft (only staff can preview it)'] as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= $val('status', 'draft') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="publish_at">Show from <span class="text-muted fw-normal">(optional)</span></label>
                    <input class="form-control<?= invalid('publish_at') ?>" type="datetime-local" id="publish_at" name="publish_at" value="<?= e((string) fv('publish_at', $dt($p['publish_at'] ?? null))) ?>">
                    <?= field_error('publish_at') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="unpublish_at">Hide after <span class="text-muted fw-normal">(optional)</span></label>
                    <input class="form-control<?= invalid('unpublish_at') ?>" type="datetime-local" id="unpublish_at" name="unpublish_at" value="<?= e((string) fv('unpublish_at', $dt($p['unpublish_at'] ?? null))) ?>">
                    <?= field_error('unpublish_at') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="sort_order">Order in the Help menu</label>
                    <input class="form-control<?= invalid('sort_order') ?>" id="sort_order" name="sort_order" inputmode="numeric" value="<?= e($val('sort_order', '100')) ?>">
                    <?= field_error('sort_order') ?>
                </div>
                <div class="col-md-8 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="show_in_nav" name="show_in_nav" value="1" <?= fchecked('show_in_nav', $isNew ? true : (int) $p['show_in_nav'] === 1) ?>>
                        <label class="form-check-label" for="show_in_nav">List in the Help Center side menu</label>
                    </div>
                </div>
                <div class="col-12"><button class="btn btn-wm-green" type="submit">Save page</button></div>
            </div>
        </form>
        <?php if (!$isNew && !$system): ?>
            <form class="mt-3" method="post" action="<?= e(url('/admin/content/pages/' . $p['id'] . '/delete')) ?>" data-confirm="Delete this page? Its address will stop working. This cannot be undone.">
                <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Delete page</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <section class="wm-panel mb-3" id="formatHelp">
            <div class="wm-panel-head">Formatting</div>
            <div class="wm-panel-body small">
                <dl class="mb-2">
                    <dt><code>## Heading</code></dt><dd>A section heading (<code>###</code> for a smaller one).</dd>
                    <dt><code>- item</code> / <code>1. item</code></dt><dd>A bulleted or numbered list.</dd>
                    <dt><code>**bold**</code> <code>*italic*</code></dt><dd>Emphasis.</dd>
                    <dt><code>[text](/contact)</code></dt><dd>A link: a page on this site, a full https:// address, mailto: or tel:.</dd>
                    <dt><code>&gt; note</code></dt><dd>A highlighted note.</dd>
                    <dt><code>?? Question</code></dt><dd>An FAQ question; the paragraph(s) below it are the answer.</dd>
                </dl>
                <p class="mb-1">Leave a blank line between paragraphs. HTML is not used — anything typed shows as text.</p>
                <p class="fw-semibold mb-1 mt-3">Live values</p>
                <ul class="list-unstyled mb-2">
                    <?php foreach (ContentRenderer::VARIABLES as $k => $l): ?><li><code>{<?= e($k) ?>}</code> <?= e($l) ?></li><?php endforeach; ?>
                </ul>
                <p class="fw-semibold mb-1">Live blocks <span class="fw-normal">(on a line of their own)</span></p>
                <ul class="list-unstyled mb-0">
                    <?php foreach (ContentRenderer::BLOCKS as $k => $l): ?><li><code>[[<?= e($k) ?>]]</code> <?= e($l) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php if (!$isNew): ?>
            <section class="wm-panel">
                <div class="wm-panel-head">Earlier versions</div>
                <?php if (!$revisions): ?><div class="wm-panel-body small text-muted">None yet — a version is kept every time the page is saved.</div><?php endif; ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($revisions as $r): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?><?= $r['first_name'] ? ' · ' . e($r['first_name']) : '' ?><span class="d-block text-muted"><?= e(ContentRenderer::plain((string) $r['title'])) ?> · <?= number_format((int) $r['length']) ?> characters</span></span>
                            <form method="post" action="<?= e(url('/admin/content/pages/' . $p['id'] . '/revisions/' . $r['id'] . '/restore')) ?>" data-confirm="Bring back this version? The current version is kept, so you can undo this.">
                                <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Restore</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
