<?php
use Waimart\Services\SettingsService;

/** @var string $group current page: a settings group slug, "branding" or "transfer" */
$items = [];
foreach (SettingsService::GROUPS as $slug => [$title, , $icon]) {
    $items[] = [$slug, $title, $icon, SettingsService::PAGES[$slug] ?? '/admin/settings/' . $slug];   // some groups live on their module page
}
array_splice($items, 1, 0, [['branding', 'Branding', 'palette', '/admin/settings/branding']]);
$items[] = ['transfer', 'Export & import', 'arrow-left-right', '/admin/settings/transfer'];
?>
<nav class="wm-panel mb-3" aria-label="Settings sections">
    <div class="list-group list-group-flush small">
        <?php foreach ($items as [$slug, $title, $icon, $href]): ?>
            <a class="list-group-item list-group-item-action d-flex align-items-center gap-2<?= $group === $slug ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $group === $slug ? ' aria-current="page"' : '' ?>>
                <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><?= e($title) ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
