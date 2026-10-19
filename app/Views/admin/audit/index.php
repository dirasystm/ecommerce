<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $pages @var int $perPage @var string $search @var string $from @var string $to
 *  @var list<string> $actions @var list<string> $entities @var list<array<string,mixed>> $actors */
$req = View::shared('request');
$q = $req->query;
$json = function (?string $j): string {
    if ($j === null || $j === '') {
        return '';
    }
    $d = json_decode($j, true);
    if (!is_array($d)) {
        return e($j);
    }
    $out = [];
    foreach ($d as $k => $v) {
        $out[] = '<span class="text-muted">' . e((string) $k) . ':</span> ' . e(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE));
    }
    return implode('<br>', $out);
};
?>
<div class="wm-admin-head"><h1>Audit log <span class="text-muted fs-6 fw-normal">(<?= number_format($total) ?>)</span></h1></div>

<form class="d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(url('/admin/audit')) ?>" role="search">
    <div class="flex-grow-1" style="min-width:220px;max-width:340px"><label class="form-label small mb-0" for="q">Search</label>
        <input class="form-control form-control-sm" type="search" id="q" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Action, record, staff, IP, details…"></div>
    <div><label class="form-label small mb-0" for="from">From</label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label class="form-label small mb-0" for="to">To</label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div><label class="form-label small mb-0" for="action">Area</label>
        <select class="form-select form-select-sm" id="action" name="action"><option value="">All</option>
            <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= ($q['action'] ?? '') === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small mb-0" for="entity">Entity</label>
        <select class="form-select form-select-sm" id="entity" name="entity"><option value="">All</option>
            <?php foreach ($entities as $en): ?><option value="<?= e($en) ?>" <?= ($q['entity'] ?? '') === $en ? 'selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small mb-0" for="entity_id">ID</label><input class="form-control form-control-sm" id="entity_id" name="entity_id" value="<?= e($q['entity_id'] ?? '') ?>" style="width:90px"></div>
    <div><label class="form-label small mb-0" for="actor">Staff</label>
        <select class="form-select form-select-sm" id="actor" name="actor"><option value="">Anyone</option>
            <?php foreach ($actors as $a): ?><option value="<?= (int) $a['id'] ?>" <?= ($q['actor'] ?? '') === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['first_name'] . ' ' . $a['last_name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small mb-0" for="per">Per page</label>
        <select class="form-select form-select-sm" id="per" name="per"><?php foreach ([25, 50, 100] as $n): ?><option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
    <?php if ($q): ?><a class="btn btn-sm btn-link" href="<?= e(url('/admin/audit')) ?>">Clear</a><?php endif; ?>
    <a class="btn btn-sm btn-wm-green ms-auto" href="<?= e(query_url('/admin/audit', ['format' => 'csv', 'page' => null])) ?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
</form>

<?php $first = $total ? ($page - 1) * $perPage + 1 : 0; $last = min($total, $page * $perPage); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <p class="small text-muted mb-0" role="status">Showing <?= number_format($first) ?>–<?= number_format($last) ?> of <?= number_format($total) ?><?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?></p>
    <?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/audit', 'compact' => true]) ?>
</div>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-sm align-top mb-0">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th><th>Before</th><th>After</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="small text-nowrap"><?= e(date('d M Y H:i:s', strtotime($r['created_at']))) ?><div class="text-muted"><?= e((string) $r['ip_address']) ?></div></td>
                <td class="small"><?= e($r['actor_name'] ?? 'System / customer') ?></td>
                <td class="small"><code><?= e($r['action']) ?></code></td>
                <td class="small text-nowrap"><?= e($r['entity_type']) ?><?= $r['entity_id'] !== null ? ' #' . e($r['entity_id']) : '' ?></td>
                <td class="small wm-audit-json"><?= $json($r['before_json']) ?></td>
                <td class="small wm-audit-json"><?= $json($r['after_json']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No audit entries<?= $search !== '' ? ' match “' . e($search) . '”' : '' ?> for these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/audit']) ?>
</div>
<p class="small text-muted mt-2">The audit log is append-only: entries cannot be edited or deleted from the application. Secrets (passwords, tokens, codes) are redacted before they are written.</p>
