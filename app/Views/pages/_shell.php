<?php
/**
 * Shared shell for content pages: breadcrumb + help navigation + body.
 * @var string $title
 * @var string $slug
 * @var string $body
 */
use Waimart\Controllers\PageController;
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
    <div class="row g-3">
        <aside class="col-lg-3">
            <nav class="wm-card wm-help-nav list-group list-group-flush py-2" aria-label="Help topics">
                <?php foreach (PageController::PAGES as $s => [$t]): $t = brand_text($t); ?>
                    <a class="list-group-item list-group-item-action<?= $s === $slug ? ' active' : '' ?>"
                       href="<?= e(url('/' . $s)) ?>" <?= $s === $slug ? 'aria-current="page"' : '' ?>><?= e($t) ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <div class="col-lg-9">
            <article class="wm-card wm-page">
                <h1><?= e($title) ?></h1>
                <?= $body ?>
            </article>
        </div>
    </div>
</div>
