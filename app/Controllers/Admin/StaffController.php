<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Auth;
use Waimart\Services\AuthEvents;
use Waimart\Services\MfaService;
use Waimart\Services\RoleService;
use Waimart\Services\StaffService;

/** Staff accounts & roles (users.manage) and read-only customer lookup (customers.view). */
final class StaffController extends AdminController
{
    public function index(Request $req): Response
    {
        $db = App::db();
        $rows = $db->all(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.last_login_at, u.mfa_enabled_at, u.must_change_password,
                    (SELECT COUNT(*) FROM user_permissions up WHERE up.user_id = u.id) AS grants,
                    GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS roles
               FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.is_staff = 1 GROUP BY u.id ORDER BY u.status, u.first_name"
        );
        $roles = $db->all('SELECT r.*, (SELECT GROUP_CONCAT(p.slug ORDER BY p.slug SEPARATOR \', \') FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = r.id) AS perms FROM roles r ORDER BY r.id');
        return $this->view('staff/index', ['title' => 'Staff', 'rows' => $rows, 'roles' => $roles]);
    }

    public function create(Request $req): Response
    {
        return $this->view('staff/form', ['title' => 'Add staff member', 'staff' => null, 'current' => [], 'roles' => $this->roles()]);
    }

    public function store(Request $req): Response
    {
        (new StaffService())->create($req->post, $this->actor(), $req->ip());
        return $this->success('Staff member added. They have been emailed a link to set their password.', '/admin/staff');
    }

    public function edit(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $staff = App::db()->one('SELECT * FROM users WHERE id = ? AND is_staff = 1', [$id]) ?? throw HttpException::notFound();
        $svc = new StaffService();
        return $this->view('staff/form', [
            'title' => 'Edit staff member', 'staff' => $staff, 'current' => $svc->rolesOf($id), 'roles' => $this->roles(),
            'grants' => $svc->grantsOf($id), 'catalog' => (new RoleService())->catalog(), 'mine' => Auth::permissionsFor($this->actor()),
            'fromRoles' => array_values(array_diff(Auth::permissionsFor($id), $svc->grantsOf($id))),
            'signIns' => AuthEvents::recent($id, false, 15),
            'actions' => App::db()->all('SELECT action, entity_type, entity_id, created_at FROM audit_logs WHERE actor_user_id = ? ORDER BY id DESC LIMIT 15', [$id]),
            'recovery' => (new MfaService())->unusedRecoveryCodes($id),
        ]);
    }

    public function updateGrants(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new StaffService())->setGrants($id, $req->post['permissions'] ?? [], $this->actor());
        return $this->success('Extra permissions saved.', "/admin/staff/$id/edit");
    }

    /** Account security actions: require a password change, send a reset link, sign out everywhere, reset two-step sign-in. */
    public function security(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $svc = new StaffService();
        $actor = $this->actor();
        switch ((string) $req->input('action', '')) {
            case 'require_password':
                $svc->requirePasswordChange($id, true, $actor);
                $msg = 'They must choose a new password the next time they use the admin.';
                break;
            case 'clear_password':
                $svc->requirePasswordChange($id, false, $actor);
                $msg = 'Password change no longer required.';
                break;
            case 'send_reset':
                $svc->sendPasswordReset($id, $actor, $req->ip());
                $msg = 'A password reset link has been emailed to them.';
                break;
            case 'revoke_sessions':
                $svc->revokeSessions($id, $actor);
                $msg = 'Signed out on every device.';
                break;
            case 'reset_mfa':
                $svc->resetMfa($id, $actor);
                $msg = 'Two-step sign-in reset and signed out everywhere. They can set it up again from their account page.';
                break;
            default:
                throw HttpException::notFound();
        }
        return $this->success($msg, "/admin/staff/$id/edit");
    }

    public function updateRoles(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new StaffService())->updateRoles($id, $req->post['roles'] ?? [], $this->actor());
        return $this->success('Roles updated.', "/admin/staff/$id/edit");
    }

    public function status(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $active = (string) $req->input('active', '') === '1';
        (new StaffService())->setActive($id, $active, $this->actor());
        return $this->success($active ? 'Staff member reactivated.' : 'Staff member suspended and signed out everywhere.', '/admin/staff');
    }

    public function revoke(Request $req): Response
    {
        (new StaffService())->revoke($this->intParam($req, 'id'), $this->actor());
        return $this->success('Staff access removed. Their history is kept in the audit log.', '/admin/staff');
    }

    // ------------------------------------------------------------------ customers (read-only)

    public function customers(Request $req): Response
    {
        $q = trim((string) ($req->query['q'] ?? ''));
        $params = [];
        $where = 'u.is_staff = 0';
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
            $where .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ?)';
            $phone = \Waimart\Core\Validator::normalizePhone($q);
            array_push($params, $like, $like, $like, $phone ? "%$phone%" : $like);
        }
        $rows = App::db()->all(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders,
                    (SELECT COALESCE(SUM(grand_total),0) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('cancelled','expired')) AS spent
               FROM users u WHERE $where ORDER BY u.id DESC LIMIT 100",
            $params
        );
        return $this->view('staff/customers', ['title' => 'Customers', 'rows' => $rows, 'q' => $q]);
    }

    public function customer(Request $req): Response
    {
        $db = App::db();
        $id = $this->intParam($req, 'id');
        $c = $db->one('SELECT * FROM users WHERE id = ? AND is_staff = 0', [$id]) ?? throw HttpException::notFound();
        \Waimart\Services\AuditLogger::log('customer.viewed', 'user', $id, null, null, $this->actor());     // personal data access is logged
        return $this->view('staff/customer', [
            'title' => $c['first_name'] . ' ' . $c['last_name'], 'c' => $c,
            'orders' => $db->all('SELECT id, order_number, status, grand_total, placed_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$id]),
            'addresses' => $db->all('SELECT a.*, co.name AS county FROM user_addresses a JOIN counties co ON co.id = a.county_id WHERE a.user_id = ?', [$id]),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function roles(): array
    {
        return App::db()->all('SELECT slug, name, description FROM roles ORDER BY is_system DESC, id');
    }
}
