<?php
use Waimart\Core\Validator;
use Waimart\Core\View;

/** @var list<array<string,mixed>> $addresses */
ob_start(); ?>
<div class="mb-3"><a class="btn btn-wm btn-sm" href="<?= e(url('/account/addresses/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add address</a></div>
<?php if (!$addresses): ?>
    <p class="text-muted">You have no saved addresses.</p>
<?php endif; ?>
<div class="row g-3">
    <?php foreach ($addresses as $a): ?>
        <div class="col-md-6">
            <div class="border rounded p-3 h-100 d-flex flex-column">
                <div class="d-flex justify-content-between">
                    <strong><?= e($a['label'] ?: $a['recipient_name']) ?></strong>
                    <?= (int) $a['is_default'] ? '<span class="badge text-bg-success">Default</span>' : '' ?>
                </div>
                <p class="small mb-1 mt-1"><?= e($a['recipient_name']) ?> · <?= e(Validator::displayPhone($a['phone'])) ?></p>
                <p class="small mb-1"><?= e($a['location']) ?>, <?= e($a['town']) ?>, <?= e($a['county_name']) ?></p>
                <?php if ($a['instructions']): ?><p class="small text-muted mb-2"><?= e($a['instructions']) ?></p><?php endif; ?>
                <div class="d-flex gap-3 mt-auto pt-2">
                    <a class="small" href="<?= e(url('/account/addresses/' . $a['id'] . '/edit')) ?>">Edit</a>
                    <?php if (!(int) $a['is_default']): ?>
                        <form method="post" action="<?= e(url('/account/addresses/' . $a['id'] . '/default')) ?>"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0" type="submit">Make default</button></form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/account/addresses/' . $a['id'] . '/delete')) ?>" data-confirm="Remove this address?"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0 text-danger" type="submit">Remove</button></form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
