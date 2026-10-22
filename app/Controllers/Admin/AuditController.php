<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Csv;
use Waimart\Core\ListParams;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\ReportService;

/** Read-only audit trail viewer (audit.view). The audit log itself is append-only. */
final class AuditController extends AdminController
{
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
        $lp = ListParams::from($req, ['when' => 'a.id'], 'when', 'desc');
        $w = implode(' AND ', $where);
        $fromSql = 'FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id';
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS actor_name $fromSql WHERE $w ORDER BY " . $lp->orderSql();

        if (($req->query['format'] ?? '') === 'csv') {
            $rows = $db->all($sql . ' LIMIT 10000', $params);   // read first, so the export's own log entry is not in it
            \Waimart\Services\AuditLogger::log('audit.exported', 'audit_log', null, null, ['from' => $from, 'to' => $to, 'action' => $action, 'entity' => $entity, 'search' => $search, 'rows' => count($rows)], $this->actor());
            return Csv::response(\Waimart\Services\Brand::slug() . '-audit-' . substr($from, 0, 10) . '-to-' . substr($to, 0, 10) . '.csv',
                ['When', 'Actor', 'Action', 'Entity', 'Entity ID', 'Before', 'After', 'IP'],
                array_map(fn ($r) => [$r['created_at'], $r['actor_name'] ?? 'System/customer', $r['action'], $r['entity_type'], $r['entity_id'], $r['before_json'], $r['after_json'], $r['ip_address']], $rows));
        }

        $lp->fit((int) $db->value("SELECT COUNT(*) $fromSql WHERE $w", $params));
        $rows = $db->all($sql . ' ' . $lp->limitSql(), $params);

        return $this->view('audit/index', [
            'title' => 'Audit log', 'rows' => $rows, 'lp' => $lp, 'search' => $search,
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
        $lp = ListParams::from($req, ['when' => 'e.id'], 'when', 'desc');
        $where = ['1 = 1'];
        $params = [];
        if ($staffOnly) {
            $where[] = 'e.is_staff = 1';
        }
        if (isset(\Waimart\Services\AuthEvents::LABELS[$event])) {
            $where[] = 'e.event = ?';
            $params[] = $event;
        }
        if ($lp->q !== '') {
            $where[] = "(e.identifier LIKE ? OR e.ip_address LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name, u.email) LIKE ?)";
            array_push($params, $lp->like(), $lp->like(), $lp->like());
        }
        $from = 'FROM auth_events e LEFT JOIN users u ON u.id = e.user_id WHERE ' . implode(' AND ', $where);
        $db = App::db();
        $lp->fit((int) $db->value("SELECT COUNT(*) $from", $params));
        return $this->view('audit/sign-ins', [
            'title' => 'Sign-in activity', 'lp' => $lp,
            'rows' => $db->all("SELECT e.*, CONCAT(u.first_name, ' ', u.last_name) AS who $from ORDER BY " . $lp->orderSql() . ' ' . $lp->limitSql(), $params),
            'staffOnly' => $staffOnly, 'event' => $event,
            'failed24h' => (int) App::db()->value("SELECT COUNT(*) FROM auth_events WHERE event IN ('login_failed','mfa_failed','login_locked') AND created_at >= NOW() - INTERVAL 1 DAY"),
        ]);
    }
}
