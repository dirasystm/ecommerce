<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $pending @var list<array<string,mixed>> $mine @var \Waimart\Core\ListParams $lp */
ob_start(); ?>
<p class="small text-muted">Only items you have received can be reviewed — every review is marked as a verified purchase. Reviews are checked by our team before they appear.</p>
<?php if ($pending): ?>
    <h2 class="fs-6 fw-bold mt-3">Waiting for your review</h2>
    <?php foreach ($pending as $it): ?>
        <details class="border rounded p-3 mb-2">
            <summary class="d-flex align-items-center gap-3">
                <img src="<?= e(media($it['image_path'])) ?>" alt="" width="48" height="48" style="object-fit:contain">
                <span><strong class="small"><?= e($it['product_name']) ?></strong><?= $it['variant_name'] !== 'Default' ? ' <span class="small text-muted">' . e($it['variant_name']) . '</span>' : '' ?>
                    <span class="d-block small text-muted">Order <?= e($it['order_number']) ?> · Write a review</span></span>
            </summary>
            <form class="mt-3" method="post" action="<?= e(url('/account/reviews/' . $it['id'])) ?>" novalidate>
                <?= csrf_field() ?>
                <fieldset class="mb-2">
                    <legend class="form-label small mb-1">Rating</legend>
                    <div class="wm-rating-input">
                        <?php for ($s = 5; $s >= 1; $s--): ?>
                            <input type="radio" id="r<?= $it['id'] ?>_<?= $s ?>" name="rating" value="<?= $s ?>" <?= $s === 5 ? 'required' : '' ?>>
                            <label for="r<?= $it['id'] ?>_<?= $s ?>" title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">★<span class="visually-hidden"><?= $s ?> star<?= $s > 1 ? 's' : '' ?></span></label>
                        <?php endfor; ?>
                    </div>
                </fieldset>
                <label class="form-label small" for="t<?= $it['id'] ?>">Title</label>
                <input class="form-control form-control-sm mb-2" id="t<?= $it['id'] ?>" name="title" maxlength="120">
                <label class="form-label small" for="b<?= $it['id'] ?>">Your review</label>
                <textarea class="form-control form-control-sm" id="b<?= $it['id'] ?>" name="body" rows="3" maxlength="2000"></textarea>
                <button class="btn btn-wm btn-sm mt-2" type="submit">Submit review</button>
            </form>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
<h2 class="fs-6 fw-bold mt-4">Your reviews</h2>
<?php foreach ($mine as $r): ?>
    <div class="border-bottom py-2 small">
        <?= stars((float) $r['rating']) ?> <strong><?= e($r['title'] ?? '') ?></strong> — <?= e($r['product_name']) ?>
        <span class="badge <?= ['approved' => 'badge-soft-success', 'rejected' => 'badge-soft-danger'][$r['status']] ?? 'badge-soft-warning' ?> ms-1"><?= e(['pending' => 'Awaiting check', 'approved' => 'Published', 'rejected' => 'Not published'][$r['status']]) ?></span>
        <?php if ($r['body']): ?><p class="mb-0 mt-1"><?= nl2br(e($r['body'])) ?></p><?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (!$mine): ?><p class="small text-muted">You have not written any reviews yet.</p><?php else: ?><?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/account/reviews', 'id' => 'myreviews', 'noun' => 'reviews', 'form' => false]) ?><?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('account/_layout', compact('title', 'section', 'body'));
