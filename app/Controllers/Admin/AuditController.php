<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Csv;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\ReportService;

/** Read-only audit trail viewer (audit.view). The audit log itself is append-only. */
final class AuditController extends AdminController
{
    private const PER_PAGE = 50;

    public function index(Request $req): Response
    {
        $db = App::db();
        [$from, $to] = ReportService::range($req->query['from'] ?? null, $req->query['to'] ?? null, 30);
        $where = ['a.created_at BETWEEN ? AND ?'];
        $params = [$from, $to];
        $action = (string) ($req->query['action'] ?? '');
        if ($action !== '' && preg_match('/^[a-z_.]+$/', $action)) {
            $where[] = 'a.action LIKE ?';
            $params[] = addcslashes($action, '%_') . '%';
        }
        $entity = (string) ($req->query['entity'] ?? '');
        if ($entity !== '' && preg_match('/^[a-z_]+$/', $entity)) {
            $where[] = 'a.entity_type = ?';
            $params[] = $entity;
            $eid = trim((string) ($req->query['entity_id'] ?? ''));
            if ($eid !== '') {
                $where[] = 'a.entity_id = ?';
                $params[] = mb_substr($eid, 0, 64);
            }
        }
        $actor = (string) ($req->query['actor'] ?? '');
        if (ctype_digit($actor)) {
            $where[] = 'a.actor_user_id = ?';
            $params[] = (int) $actor;
        }
        // Free-text search: action, record, staff name or email, IP address and the recorded before/after details.
        $search = trim(mb_substr((string) ($req->query['q'] ?? ''), 0, 100));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = "(a.action LIKE ? OR a.entity_type LIKE ? OR a.entity_id LIKE ? OR a.ip_address LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
                         OR u.email LIKE ? OR a.before_json LIKE ? OR a.after_json LIKE ?)";
            array_push($params, ...array_fill(0, 8, $like));
        }
        $perPage = in_array((int) ($req->query['per'] ?? 0), [25, 50, 100], true) ? (int) $req->query['per'] : self::PER_PAGE;
        $w = implode(' AND ', $where);
        $fromSql = 'FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id';
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS actor_name $fromSql WHERE $w ORDER BY a.id DESC";

        if (($req->query['format'] ?? '') === 'csv') {
            $rows = $db->all($sql . ' LIMIT 10000', $params);   // read first, so the export's own log entry is not in it
            \Waimart\Services\AuditLogger::log('audit.exported', 'audit_log', null, null, ['from' => $from, 'to' => $to, 'action' => $action, 'entity' => $entity, 'search' => $search, 'rows' => count($rows)], $this->actor());
            return Csv::response(\Waimart\Services\Brand::slug() . '-audit-' . substr($from, 0, 10) . '-to-' . substr($to, 0, 10) . '.csv',
                ['When', 'Actor', 'Action', 'Entity', 'Entity ID', 'Before', 'After', 'IP'],
                array_map(fn ($r) => [$r['created_at'], $r['actor_name'] ?? 'System/customer', $r['action'], $r['entity_type'], $r['entity_id'], $r['before_json'], $r['after_json'], $r['ip_address']], $rows));
        }

        $total = (int) $db->value("SELECT COUNT(*) $fromSql WHERE $w", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($req->query['page'] ?? 1)));
        $rows = $db->all($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

        return $this->view('audit/index', [
            'title' => 'Audit log', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage, 'search' => $search,
            'from' => substr($from, 0, 10), 'to' => substr($to, 0, 10),
            'actions' => array_column($db->all("SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) AS a FROM audit_logs ORDER BY a"), 'a'),
            'entities' => array_column($db->all('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type'), 'entity_type'),
            'actors' => $db->all('SELECT DISTINCT u.id, u.first_name, u.last_name FROM audit_logs a JOIN users u ON u.id = a.actor_user_id ORDER BY u.first_name'),
        ]);
    }

    /** Sign-in activity (auth_events): successful and failed sign-ins, two-step checks and account security changes. */
    public function signIns(Request $req): Response
    {
        $staffOnly = ($req->query['who'] ?? 'staff') !== 'all';
        $event = (string) ($req->query['event'] ?? '');
        return $this->view('audit/sign-ins', [
            'title' => 'Sign-in activity', 'rows' => \Waimart\Services\AuthEvents::recent(null, $staffOnly, 200, $event !== '' ? $event : null),
            'staffOnly' => $staffOnly, 'event' => $event,
            'failed24h' => (int) App::db()->value("SELECT COUNT(*) FROM auth_events WHERE event IN ('login_failed','mfa_failed','login_locked') AND created_at >= NOW() - INTERVAL 1 DAY"),
        ]);
    }
}
