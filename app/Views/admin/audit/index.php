<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var \Waimart\Core\ListParams $lp @var string $search @var string $from @var string $to
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
<div class="wm-admin-head"><h1>Audit log</h1></div>
<?php
$opts = fn (array $list, string $all) => ['' => $all] + ($list ? array_combine($list, $list) : []);
$actorOpts = ['' => 'Anyone'];
foreach ($actors as $a) {
    $actorOpts[(string) $a['id']] = $a['first_name'] . ' ' . $a['last_name'];
}
?>
<?= View::partial('partials/table/toolbar', ['id' => 'audit', 'path' => '/admin/audit', 'lp' => $lp, 'label' => 'Search the audit log',
    'placeholder' => 'Action, record, staff, IP, details…',
    'filters' => [
        ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => $from, 'default' => $from],
        ['name' => 'to', 'label' => 'To', 'type' => 'date', 'value' => $to, 'default' => $to],
        ['name' => 'action', 'label' => 'Area', 'value' => (string) ($q['action'] ?? ''), 'options' => $opts($actions, 'All areas')],
        ['name' => 'entity', 'label' => 'Entity', 'value' => (string) ($q['entity'] ?? ''), 'options' => $opts($entities, 'All entities')],
        ['name' => 'entity_id', 'label' => 'ID', 'type' => 'text', 'value' => (string) ($q['entity_id'] ?? ''), 'width' => '120px'],
        ['name' => 'actor', 'label' => 'Staff', 'value' => (string) ($q['actor'] ?? ''), 'options' => $actorOpts],
    ],
    'actions' => '<a class="btn btn-sm btn-wm-green" href="' . e(query_url('/admin/audit', ['format' => 'csv', 'page' => null])) . '"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>']) ?>
<div class="wm-panel">
    <div id="audit" class="wm-table-region" data-table-region data-loading-text="Loading audit entries…">
        <div class="table-responsive">
            <table class="table table-sm align-top mb-0">
                <caption class="visually-hidden">Audit log entries</caption>
                <thead><tr><?= sort_th('When', 'when', $lp, '/admin/audit') ?><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Entity</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
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
                <?php if (!$rows): ?><?= empty_row(6, true, 'No audit entries.', 'No audit entries match these filters.') ?><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/table/footer', ['lp' => $lp, 'path' => '/admin/audit', 'id' => 'audit', 'noun' => 'entries']) ?>
    </div>
</div>
<p class="small text-muted mt-2">The audit log is append-only: entries cannot be edited or deleted from the application. Secrets (passwords, tokens, codes) are redacted before they are written.</p>
