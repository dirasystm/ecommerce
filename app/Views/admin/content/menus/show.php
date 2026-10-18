<?php
use Waimart\Services\SiteContentService;

/** @var array<string,mixed> $m @var list<array<string,mixed>> $items @var array<string,mixed>|null $editing */
$slug = (string) $m['slug'];
$dropdowns = in_array($slug, SiteContentService::DROPDOWN_MENUS, true);
$linkRequired = in_array($slug, SiteContentService::LINKED_MENUS, true);
$tops = array_values(array_filter($items, fn ($i) => (int) $i['depth'] === 0));
$f = fn (string $k, string $d = '') => (string) fv($k, $editing[$k] ?? $d);
$action = $editing ? '/admin/content/menus/' . $slug . '/items/' . $editing['id'] : '/admin/content/menus/' . $slug . '/items';
?>
<div class="wm-admin-head"><h1><?= e(brand_text((string) $m['title'])) ?></h1></div>
<p><a href="<?= e(url('/admin/content/menus')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Menus</a></p>
<p class="small text-muted"><?= e($m['description']) ?> Labels may use <code>{store}</code> for the store name.</p>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="wm-panel mb-3">
            <div class="wm-panel-head">Items</div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Order</th><th>Item</th><th>Link</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr class="<?= (int) $i['is_visible'] ? '' : 'text-muted' ?>">
                        <td class="text-nowrap">
                            <?php foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $dir => $icon): ?>
                                <form class="d-inline" method="post" action="<?= e(url('/admin/content/menus/' . $slug . '/items/' . $i['id'] . '/move')) ?>"><?= csrf_field() ?>
                                    <input type="hidden" name="direction" value="<?= $dir ?>">
                                    <button class="btn btn-sm btn-link p-0 me-1" type="submit" aria-label="Move <?= e(brand_text((string) $i['label'])) ?> <?= $dir ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></button></form>
                            <?php endforeach; ?>
                        </td>
                        <td><?= (int) $i['depth'] ? '<span class="text-muted me-1" aria-hidden="true">↳</span>' : '' ?><?php if ($i['icon']): ?><i class="bi bi-<?= e($i['icon']) ?> me-1" aria-hidden="true"></i><?php endif; ?><?= e(brand_text((string) $i['label'])) ?>
                            <?= (int) $i['is_visible'] ? '' : ' <span class="badge badge-soft-muted">hidden</span>' ?>
                            <?php if ($i['description']): ?><div class="small text-muted"><?= e(brand_text((string) $i['description'])) ?></div><?php endif; ?></td>
                        <td class="small"><?= $i['link'] ? '<code>' . e($i['link']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/content/menus/' . $slug, ['edit' => $i['id']])) ?>">Edit</a>
                            <form class="d-inline" method="post" action="<?= e(url('/admin/content/menus/' . $slug . '/items/' . $i['id'] . '/delete')) ?>" data-confirm="Remove this item<?= (int) $i['depth'] ? '' : ' and its drop-down items' ?>? (To keep it for later, edit it and untick Visible instead.)"><?= csrf_field() ?>
                                <button class="btn btn-sm btn-link text-danger" type="submit">Remove</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?><tr><td colspan="4" class="text-center text-muted py-3">No items: this place is empty on the site.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <?php if ($slug === 'mobile_nav'): ?><div class="wm-panel-body small text-muted pt-2">Phones show the first <?= SiteContentService::MOBILE_NAV_MAX ?> visible items.</div><?php endif; ?>
        </section>
        <form class="wm-panel" method="post" action="<?= e(url('/admin/content/menus/' . $slug . '/title')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="wm-panel-body d-flex flex-wrap gap-2 align-items-end">
                <div class="flex-grow-1"><label class="form-label small" for="menu_title">Menu name<?= str_starts_with($slug, 'footer_col_') ? ' (shown as the column heading)' : '' ?></label>
                    <input class="form-control form-control-sm<?= invalid('menu_title') ?>" id="menu_title" name="menu_title" maxlength="80" value="<?= e((string) fv('menu_title', $m['title'])) ?>">
                    <?= field_error('menu_title') ?></div>
                <button class="btn btn-sm btn-outline-secondary" type="submit">Save name</button>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <form class="wm-panel" method="post" action="<?= e(url($action)) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="wm-panel-head"><?= $editing ? 'Edit item' : 'Add an item' ?></div>
            <div class="wm-panel-body">
                <label class="form-label small" for="label">Label</label>
                <input class="form-control form-control-sm<?= invalid('label') ?>" id="label" name="label" maxlength="80" value="<?= e($f('label')) ?>">
                <?= field_error('label') ?>
                <label class="form-label small mt-2" for="link">Link<?= $linkRequired ? '' : ' <span class="text-muted">(optional)</span>' ?></label>
                <input class="form-control form-control-sm<?= invalid('link') ?>" id="link" name="link" maxlength="255" value="<?= e($f('link')) ?>" placeholder="/help or https://…" aria-describedby="linkHelp">
                <div class="form-text" id="linkHelp">A page on this site starting with /, a full https:// address, mailto: or tel:.</div>
                <?= field_error('link') ?>
                <label class="form-label small mt-2" for="icon">Icon <span class="text-muted">(optional)</span></label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-<?= e($f('icon') ?: 'square') ?>" aria-hidden="true"></i></span>
                    <input class="form-control<?= invalid('icon') ?>" id="icon" name="icon" maxlength="40" value="<?= e($f('icon')) ?>" placeholder="truck" aria-describedby="iconHelp">
                </div>
                <div class="form-text" id="iconHelp">A Bootstrap icon name, e.g. truck, shop, phone, question-circle (<a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">list of icons</a>).</div>
                <?= field_error('icon') ?>
                <label class="form-label small mt-2" for="description">Short text <span class="text-muted">(optional; shown on homepage tiles)</span></label>
                <input class="form-control form-control-sm<?= invalid('description') ?>" id="description" name="description" maxlength="160" value="<?= e($f('description')) ?>">
                <?= field_error('description') ?>
                <?php if ($dropdowns): ?>
                    <label class="form-label small mt-2" for="parent_id">Show inside</label>
                    <select class="form-select form-select-sm<?= invalid('parent_id') ?>" id="parent_id" name="parent_id">
                        <option value="">— top level —</option>
                        <?php foreach ($tops as $t): if ($editing && (int) $t['id'] === (int) $editing['id']) continue; ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $f('parent_id') === (string) $t['id'] ? 'selected' : '' ?>><?= e(brand_text((string) $t['label'])) ?> (drop-down)</option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('parent_id') ?>
                <?php endif; ?>
                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="is_visible" name="is_visible" value="1" <?= fchecked('is_visible', $editing ? (int) $editing['is_visible'] === 1 : true) ?>>
                    <label class="form-check-label small" for="is_visible">Visible</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="new_tab" name="new_tab" value="1" <?= fchecked('new_tab', $editing ? (int) $editing['new_tab'] === 1 : false) ?>>
                    <label class="form-check-label small" for="new_tab">Open in a new tab</label></div>
                <button class="btn btn-wm-green w-100 mt-3" type="submit"><?= $editing ? 'Save item' : 'Add item' ?></button>
                <?php if ($editing): ?><a class="btn btn-link btn-sm w-100 mt-1" href="<?= e(url('/admin/content/menus/' . $slug)) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
