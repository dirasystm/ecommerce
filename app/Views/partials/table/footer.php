<?php
use Waimart\Core\ListParams;

/**
 * Table footer: "Showing 1–10 of 248 orders", rows per page and page links (inside the table region).
 * @var \Waimart\Core\ListParams $lp (after fit())
 * @var string $path
 * @var string $id    table region id (the rows-per-page select belongs to "$id-form" when that form exists)
 * @var string $noun  plural, e.g. "orders" (default "records")
 * @var bool $form    whether a toolbar form exists (default true)
 */
$noun ??= 'records';
$form ??= true;
$first = $lp->total === 0 ? 0 : $lp->offset() + 1;
$last = min($lp->total, $lp->offset() + $lp->per);
?>
<div class="wm-table-footer">
    <div class="wm-table-count" role="status"><?= $lp->total === 0 ? 'No ' . e($noun) : 'Showing ' . number_format($first) . '–' . number_format($last) . ' of ' . number_format($lp->total) . ' ' . e($noun) ?></div>
    <?php if ($lp->total > ListParams::SIZES[0]): ?>
        <form class="wm-table-size" method="get" action="<?= e(url($path)) ?>" data-live-size="<?= e($id) ?>">
            <?php foreach ((\Waimart\Core\View::shared('request')?->query ?? []) as $k => $v): if (in_array($k, ['per', 'page'], true) || !is_scalar($v)) { continue; } ?>
                <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
            <?php endforeach; ?>
            <label for="<?= e($id) ?>-per">Rows per page</label>
            <select class="form-select form-select-sm" id="<?= e($id) ?>-per" name="per" data-live-per>
                <?php foreach (ListParams::SIZES as $n): ?><option value="<?= $n ?>" <?= $lp->per === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button></noscript>
        </form>
    <?php endif; ?>
    <?= \Waimart\Core\View::partial('partials/pagination', ['page' => $lp->page, 'pages' => $lp->pages(), 'path' => $path, 'compact' => true]) ?>
</div>
