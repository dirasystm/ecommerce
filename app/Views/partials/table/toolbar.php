<?php
/**
 * Table toolbar: live search, filters and (hidden) the current sort. A plain GET form, so it works without JavaScript;
 * with JavaScript the table region updates in place (app.js: data-live-table).
 *
 * @var string $id         id of the table region this form updates (the form gets "$id-form")
 * @var string $path       page path
 * @var \Waimart\Core\ListParams $lp
 * @var string $label      accessible label of the search box, e.g. "Search orders"
 * @var string $placeholder what can be searched
 * @var list<array{name:string,label:string,value:string,options?:array<string,string>,type?:string,width?:string}> $filters
 *      select (with options; default), 'date' or 'text' filters; their labels are visible above date and text boxes
 * @var string $actions    trusted HTML placed at the end of the toolbar (e.g. an Export button), already escaped by the caller
 * @var array<string,string> $keep other query values to keep (e.g. a tab)
 * @var bool $search       show the search box (default true)
 */
$filters ??= [];
$keep ??= [];
$search ??= true;
$active = $lp->q !== '' || array_filter($filters, fn ($f) => ($f['value'] ?? '') !== '' && ($f['value'] ?? '') !== ($f['default'] ?? ''));
?>
<form class="wm-table-toolbar" method="get" action="<?= e(url($path)) ?>" id="<?= e($id) ?>-form" data-live-table="<?= e($id) ?>" role="search" aria-label="<?= e($label) ?>">
    <?php foreach ($keep as $k => $v): if ($v === '' || $v === null) { continue; } ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <?php if ($lp->sort !== ''): ?><input type="hidden" name="sort" value="<?= e($lp->sort) ?>"><input type="hidden" name="dir" value="<?= e($lp->dir) ?>"><?php endif; ?>
    <?php if ($lp->per !== \Waimart\Core\ListParams::DEFAULT_SIZE): ?><input type="hidden" name="per" value="<?= (int) $lp->per ?>" data-per-mirror><?php endif; ?>
    <?php if ($search): ?>
        <div class="wm-search">
            <label class="visually-hidden" for="<?= e($id) ?>-q"><?= e($label) ?></label>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input class="form-control form-control-sm" type="search" id="<?= e($id) ?>-q" name="q" maxlength="100" value="<?= e($lp->q) ?>"
                   placeholder="<?= e($placeholder ?? 'Search') ?>" autocomplete="off" data-live-search aria-controls="<?= e($id) ?>">
            <button class="wm-search-clear" type="button" aria-label="Clear search" data-search-clear<?= $lp->q === '' ? ' hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    <?php endif; ?>
    <?php foreach ($filters as $f): $type = $f['type'] ?? 'select'; $fid = $id . '-' . $f['name']; ?>
        <div class="wm-filter wm-filter-<?= e($type) ?>"<?= isset($f['width']) ? ' style="flex-basis:' . e($f['width']) . '"' : '' ?>>
            <?php if ($type === 'select'): ?>
                <label class="visually-hidden" for="<?= e($fid) ?>"><?= e($f['label']) ?></label>
                <select class="form-select form-select-sm" id="<?= e($fid) ?>" name="<?= e($f['name']) ?>" data-live-filter aria-controls="<?= e($id) ?>">
                    <?php foreach ($f['options'] as $val => $text): ?><option value="<?= e((string) $val) ?>" <?= (string) $f['value'] === (string) $val ? 'selected' : '' ?>><?= e($text) ?></option><?php endforeach; ?>
                </select>
            <?php else: ?>
                <label class="wm-filter-label" for="<?= e($fid) ?>"><?= e($f['label']) ?></label>
                <input class="form-control form-control-sm" type="<?= $type === 'date' ? 'date' : 'text' ?>" id="<?= e($fid) ?>" name="<?= e($f['name']) ?>" value="<?= e((string) $f['value']) ?>"
                       maxlength="64" data-live-filter aria-controls="<?= e($id) ?>">
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <button class="btn btn-sm btn-outline-secondary" type="submit" data-live-submit>Search</button>
    <?php if ($active): ?><a class="btn btn-sm btn-link" href="<?= e(url($path, array_filter($keep, fn ($v) => $v !== '' && $v !== null))) ?>" data-live-reset>Reset</a><?php endif; ?>
    <?php if (!empty($actions)): ?><div class="wm-toolbar-actions"><?= $actions ?></div><?php endif; ?>
</form>
