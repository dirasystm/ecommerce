<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $items @var \Waimart\Core\ListParams $lp */
ob_start(); ?>
<?php if (!$items): ?>
    <div class="text-center py-4">
        <i class="bi bi-heart fs-1 text-muted" aria-hidden="true"></i>
        <p class="mt-2 mb-3">Your wishlist is empty. Tap the heart on any product to save it here.</p>
        <a class="btn btn-wm" href="<?= e(url('/')) ?>">Browse products</a>
    </div>
<?php else: ?>
    <div class="wm-grid wm-grid-listing p-0">
        <?php foreach ($items as $p): ?>
            <div>
                <?= View::partial('partials/product-card', ['p' => $p]) ?>
                <form method="post" action="<?= e(url('/wishlist/' . $p['id'] . '/toggle')) ?>" class="text-center mt-1">
                    <?= csrf_field() ?>
                    <button class="btn btn-link btn-sm text-danger" type="submit"><i class="bi bi-trash" aria-hidden="true"></i> Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/account/wishlist', 'id' => 'wishlist', 'noun' => 'saved products', 'form' => false]) ?>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
