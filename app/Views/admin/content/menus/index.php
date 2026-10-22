<?php
/** @var list<array<string,mixed>> $rows */
?>
<div class="wm-admin-head"><h1>Menus</h1></div>
<p class="small text-muted">Each menu is a fixed place on the site. Change its items, their order, links and icons, or hide an item without deleting it.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th scope="col">Menu</th><th scope="col">Where it appears</th><th scope="col" class="text-end">Items</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
            <tr><td><a class="fw-semibold" href="<?= e(url('/admin/content/menus/' . $m['slug'])) ?>"><?= e(brand_text((string) $m['title'])) ?></a></td>
                <td class="small"><?= e($m['description']) ?></td><td class="text-end"><?= (int) $m['items'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
