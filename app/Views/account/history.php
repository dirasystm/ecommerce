<?php
use Waimart\Core\View;

/**
 * @var bool $allowed @var list<array<string,mixed>> $viewed @var list<array<string,mixed>> $revisited @var list<array<string,mixed>> $bought
 * @var list<string> $searches @var list<array<string,mixed>> $forYou @var list<string> $interests
 */
$rail = function (string $title, array $cards, ?string $rec = null, bool $removable = false, bool $dismiss = false): string {
    if (!$cards) {
        return '';
    }
    ob_start(); ?>
    <h2 class="fs-6 fw-bold text-uppercase mt-4 mb-2"><?= e($title) ?></h2>
    <div class="wm-rail" tabindex="0" aria-label="<?= e($title) ?>, scroll horizontally">
        <?php foreach ($cards as $c): ?>
            <div class="wm-history-item">
                <?= View::partial('partials/product-card', ['p' => $c, 'rec' => $rec, 'dismiss' => $dismiss]) ?>
                <?php if ($removable): ?>
                    <form method="post" action="<?= e(url('/account/history/forget')) ?>"><?= csrf_field() ?>
                        <input type="hidden" name="what" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-link btn-sm p-0 small" type="submit">Remove from history<span class="visually-hidden">: <?= e($c['name']) ?></span></button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php return (string) ob_get_clean();
};
ob_start(); ?>
<?php if (!$allowed): ?>
    <div class="alert alert-info small">Personalised suggestions are switched off, so we are not recording what you browse.
        You can switch them on under <a href="<?= e(url('/account/privacy')) ?>">Privacy &amp; marketing</a>.</div>
<?php elseif ($interests): ?>
    <p class="small text-muted mb-1">We suggest products based on what you view, save, add to your cart and buy here. Lately you have been looking at:</p>
    <p class="mb-2"><?php foreach ($interests as $name): ?><span class="badge badge-soft-success me-1"><?= e($name) ?></span><?php endforeach; ?></p>
<?php endif; ?>

<?php if ($forYou): \Waimart\Services\Marketing\Recommender::shown('account', count($forYou)); ?>
    <?= $rail('Suggested for you', $forYou, 'account', false, true) ?>
<?php endif; ?>
<?= $rail('Buy again', $bought) ?>
<?= $rail('Recently viewed', $viewed, null, true) ?>
<?= $rail('You keep coming back to', $revisited, null, true) ?>

<?php if ($searches): ?>
    <h2 class="fs-6 fw-bold text-uppercase mt-4 mb-2">Recent searches</h2>
    <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
        <?php foreach ($searches as $q): ?>
            <li class="wm-search-chip">
                <a href="<?= e(url('/search', ['q' => $q])) ?>"><?= e($q) ?></a>
                <form method="post" action="<?= e(url('/account/history/forget')) ?>" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="what" value="q:<?= e($q) ?>">
                    <button type="submit" class="btn btn-link p-0 ms-1" aria-label="Remove search <?= e($q) ?>"><i class="bi bi-x" aria-hidden="true"></i></button></form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (!$viewed && !$bought && !$searches): ?>
    <p class="text-muted mt-3">Nothing here yet. Products you view, search for and buy will appear here.</p>
<?php endif; ?>

<?php if ($viewed || $searches): ?>
    <form method="post" action="<?= e(url('/account/history/forget')) ?>" class="mt-4" data-confirm="Clear your whole browsing history? Your suggestions will start fresh. Orders are not affected.">
        <?= csrf_field() ?><input type="hidden" name="what" value="all">
        <button class="btn btn-sm btn-outline-danger" type="submit">Clear my browsing history</button>
    </form>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
