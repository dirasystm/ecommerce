<?php
use Waimart\Core\View;

$tree = View::shared('categories', []);
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">All categories</li>
        </ol>
    </nav>
    <div class="wm-card wm-page">
        <h1>All categories</h1>
        <div class="row g-4">
            <?php foreach ($tree as $cat): ?>
                <div class="col-sm-6 col-lg-4">
                    <h2 class="fs-6 fw-bold mb-2">
                        <a href="<?= e(url('/category/' . $cat['slug'])) ?>">
                            <i class="bi bi-<?= e($cat['icon'] ?: 'grid') ?> me-1" aria-hidden="true"></i><?= e($cat['name']) ?>
                        </a>
                    </h2>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($cat['children'] as $child): ?>
                            <li class="py-1"><a class="text-body" href="<?= e(url('/category/' . $child['slug'])) ?>"><?= e($child['name']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            <?php if (!$tree): ?>
                <p class="text-muted">Categories will appear here soon.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
