<?php
/** @var int $page @var int $pages @var string $path @var bool $compact optional: no padding, aligned right (above a table) */
if ($pages <= 1) {
    return;
}
$window = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn ($n) => $n >= 1 && $n <= $pages));
sort($window);
?>
<nav aria-label="<?= !empty($compact) ? 'Pagination (top)' : 'Pagination' ?>" class="d-flex <?= !empty($compact) ? 'justify-content-end' : 'justify-content-center py-3' ?>">
    <ul class="pagination mb-0<?= !empty($compact) ? ' pagination-sm' : '' ?>">
        <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e(query_url($path, ['page' => $page - 1])) ?>" aria-label="Previous page"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
        </li>
        <?php $prev = 0; foreach ($window as $n): ?>
            <?php if ($n - $prev > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item<?= $n === $page ? ' active' : '' ?>">
                <a class="page-link" href="<?= e(query_url($path, ['page' => $n])) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
            </li>
        <?php $prev = $n; endforeach; ?>
        <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e(query_url($path, ['page' => $page + 1])) ?>" aria-label="Next page"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </li>
    </ul>
</nav>
