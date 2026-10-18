<?php /** @var list<array<string,mixed>> $rows @var string $status */ ?>
<div class="wm-admin-head"><h1>Product reviews</h1></div>
<ul class="nav nav-pills mb-3 small">
    <?php foreach (['pending' => 'To moderate', 'approved' => 'Published', 'rejected' => 'Rejected'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/reviews', ['status' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<p class="small text-muted">All reviews come from customers who received the product (verified purchases). Publish honest reviews, including negative ones; reject only abuse, personal data, spam or off-topic content.</p>
<?php foreach ($rows as $r): ?>
    <section class="wm-panel mb-2 wm-panel-body">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div><?= stars((float) $r['rating']) ?> <strong><?= e($r['title'] ?? '') ?></strong>
                <div class="small text-muted"><a href="<?= e(url('/product/' . $r['slug'])) ?>" target="_blank" rel="noopener"><?= e($r['product']) ?></a> · <?= e($r['first_name'] . ' ' . $r['last_name']) ?> · <?= e($r['order_number']) ?> · <?= e(date('d M Y', strtotime($r['created_at']))) ?></div></div>
            <?php if ($status === 'pending'): ?>
                <form class="d-flex gap-1 align-items-start" method="post" action="<?= e(url('/admin/reviews/' . $r['id'])) ?>">
                    <?= csrf_field() ?>
                    <input class="form-control form-control-sm" name="note" maxlength="200" placeholder="Note (optional)" aria-label="Moderation note">
                    <button class="btn btn-sm btn-wm-green" name="decision" value="approved" type="submit">Publish</button>
                    <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected" type="submit">Reject</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($r['body']): ?><p class="mb-0 mt-2 small"><?= nl2br(e($r['body'])) ?></p><?php endif; ?>
    </section>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="wm-panel wm-panel-body text-center text-muted">Nothing here.</div><?php endif; ?>
