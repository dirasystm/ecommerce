<?php
use Waimart\Core\View;

/**
 * A row of recommended products (counts as one impression of the placement).
 * @var string $title @var list<array<string,mixed>> $cards @var string $placement @var bool $dismiss @var string|null $note
 */
$dismiss ??= false;
$note ??= null;
if (!$cards) {
    return;
}
\Waimart\Services\Marketing\Recommender::shown($placement, count($cards));
$hid = 'rec-' . $placement;
?>
<section class="wm-card wm-section" aria-labelledby="<?= e($hid) ?>">
    <div class="wm-section-head">
        <h2 id="<?= e($hid) ?>"><?= e($title) ?></h2>
        <?php if ($note): ?><span class="small text-muted"><?= e($note) ?></span><?php endif; ?>
    </div>
    <div class="wm-rail" tabindex="0" aria-label="<?= e($title) ?>, scroll horizontally">
        <?php foreach ($cards as $c): ?><?= View::partial('partials/product-card', ['p' => $c, 'rec' => $placement, 'dismiss' => $dismiss]) ?><?php endforeach; ?>
    </div>
</section>
