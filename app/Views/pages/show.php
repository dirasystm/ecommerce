<?php
/**
 * A content page: breadcrumb, Help Center side menu, rendered body (ContentRenderer output — already safe HTML).
 * @var string $title @var string $slug @var string $body @var list<array<string,mixed>> $nav @var bool $preview
 */
use Waimart\Services\ContentRenderer;
use Waimart\Services\SiteContentService;
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <?php if ($slug !== 'help'): ?>
                <li class="breadcrumb-item"><a href="<?= e(url('/help')) ?>">Help Center</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?= e($title) ?></li>
        </ol>
    </nav>
    <?php if ($preview): ?>
        <div class="alert alert-warning small" role="status">Preview: this page is not published (or is scheduled), so only staff can see it.</div>
    <?php endif; ?>
    <div class="row g-3">
        <?php if ($nav): ?>
            <aside class="col-lg-3">
                <nav class="wm-card wm-help-nav list-group list-group-flush py-2" aria-label="Help topics">
                    <?php foreach ($nav as $p): ?>
                        <a class="list-group-item list-group-item-action<?= $p['slug'] === $slug ? ' active' : '' ?>"
                           href="<?= e(url(SiteContentService::pageUrl((string) $p['slug']))) ?>" <?= $p['slug'] === $slug ? 'aria-current="page"' : '' ?>><?= e(ContentRenderer::plain((string) $p['title'])) ?></a>
                    <?php endforeach; ?>
                </nav>
            </aside>
        <?php endif; ?>
        <div class="<?= $nav ? 'col-lg-9' : 'col-12' ?>">
            <article class="wm-card wm-page">
                <h1><?= e($title) ?></h1>
                <?= $body ?>
            </article>
        </div>
    </div>
</div>
