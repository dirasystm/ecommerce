<?php /** @var \Waimart\Core\ListParams $lp @var list<array<string,mixed>> $rows @var string $state */ ?>
<div class="wm-admin-head">
    <h1>Flash sales</h1>
    <a class="btn btn-wm-green" href="<?= e(url('/admin/flash-sales/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New flash sale</a>
</div>
<?= \Waimart\Core\View::partial('partials/table/toolbar', ['id' => 'sales', 'path' => '/admin/flash-sales', 'lp' => $lp, 'label' => 'Search flash sales', 'placeholder' => 'Sale title',
    'filters' => [['name' => 'state', 'label' => 'Status', 'value' => $state, 'options' => ['' => 'Any status', 'live' => 'Live', 'scheduled' => 'Scheduled', 'ended' => 'Ended', 'off' => 'Off']]]]) ?>
<div class="wm-panel">
    <div id="sales" class="wm-table-region" data-table-region data-loading-text="Loading flash sales…">
        <div class="table-responsive">
    <table class="table table-hover mb-0">
        <caption class="visually-hidden">Flash sales</caption>
        <thead><tr><th scope="col">Title</th><th scope="col">Starts</th><th scope="col">Ends</th><th scope="col" class="text-end">Items</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s):
            $now = time(); $st = strtotime($s['starts_at']); $en = strtotime($s['ends_at']);
            $state = !(int) $s['is_active'] ? ['Off', 'badge-soft-muted'] : ($now < $st ? ['Scheduled', 'badge-soft-warning'] : ($now < $en ? ['Live', 'badge-soft-success'] : ['Ended', 'badge-soft-muted'])); ?>
            <tr>
                <td class="fw-semibold"><?= e($s['title']) ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', $st)) ?></td>
                <td class="small text-nowrap"><?= e(date('d M Y H:i', $en)) ?></td>
                <td class="text-end"><?= (int) $s['items'] ?></td>
                <td><span class="badge <?= $state[1] ?>"><?= $state[0] ?></span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/flash-sales/' . $s['id'])) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><?= empty_row(6, $lp->q !== '' || $state !== '', 'No flash sales yet.') ?><?php endif; ?>
        </tbody>
    </table>
</div>
        <?= \Waimart\Core\View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/flash-sales', 'id' => 'sales', 'noun' => 'flash sales']) ?>
    </div>
</div>
<p class="small text-muted mt-2">Sale prices apply automatically between the start and end time, on the product page, in the cart and at checkout. Orders keep the price paid.</p>
