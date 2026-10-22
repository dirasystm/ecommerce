<?php
use Waimart\Core\Auth;

/** Sub-navigation of Marketing Intelligence. @var string $active */
$tabs = [
    ['/admin/marketing', 'Overview', 'marketing.view'],
    ['/admin/marketing/campaigns', 'Campaigns & links', 'marketing.view'],
    ['/admin/marketing/leads', 'Leads', 'marketing.view'],
    ['/admin/marketing/segments', 'Segments', 'marketing.view'],
    ['/admin/marketing/automations', 'Automations', 'marketing.view'],
    ['/admin/marketing/recommendations', 'Recommendations', 'marketing.view'],
    ['/admin/marketing/consent', 'Consent', 'marketing.view'],
    ['/admin/marketing/settings', 'Settings', 'marketing.manage'],
];
?>
<nav aria-label="Marketing Intelligence sections" class="mb-3">
    <ul class="nav nav-pills small flex-nowrap overflow-auto wm-mkt-tabs">
        <?php foreach ($tabs as [$href, $label, $perm]): if (!Auth::can($perm)) { continue; } ?>
            <li class="nav-item"><a class="nav-link text-nowrap<?= $active === $href ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
