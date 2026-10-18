<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Staff accounts and roles (users.manage). Safeguards:
 *   - nobody changes their own roles or suspends themselves;
 *   - only a super admin can grant or revoke super admin;
 *   - the last active super admin can never be removed or suspended;
 *   - new staff set their own password via an emailed link (no passwords are handed out).
 */
final class StaffService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return list<string> role slugs */
    public function rolesOf(int $userId): array
    {
        return array_column($this->db->all('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$userId]), 'slug');
    }

    /** @param array<string,mixed> $in */
    public function create(array $in, int $actorId, string $ip): int
    {
        Validator::validate($in, ['first_name' => 'required|max:80', 'last_name' => 'required|max:80', 'email' => 'required|email', 'phone' => 'nullable|phone']);
        $roles = $this->validRoles($in['roles'] ?? [], $actorId);
        $email = strtolower(trim((string) $in['email']));
        $id = $this->db->transaction(function () use ($in, $roles, $email, $actorId) {
            $existing = $this->db->one('SELECT * FROM users WHERE email = ? FOR UPDATE', [$email]);
            if ($existing !== null && (int) $existing['is_staff'] === 1) {
                throw new ValidationException(['email' => 'This person is already a staff member.']);
            }
            if ($existing !== null) {
                $id = (int) $existing['id'];       // promote an existing customer account
                $this->db->exec("UPDATE users SET is_staff = 1, status = 'active' WHERE id = ?", [$id]);
            } else {
                $id = $this->db->insert('users', [
                    'email' => $email,
                    // Unusable random password: the staff member sets their own via the emailed link.
                    'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    'first_name' => trim((string) $in['first_name']),
                    'last_name' => trim((string) $in['last_name']),
                    'phone' => ($p = trim((string) ($in['phone'] ?? ''))) !== '' ? Validator::normalizePhone($p) : null,
                    'is_staff' => 1,
                ]);
            }
            $this->replaceRoles($id, $roles);
            AuditLogger::log('staff.created', 'user', $id, null, ['email' => $email, 'roles' => $roles, 'promoted' => $existing !== null], $actorId);
            return $id;
        });
        (new AccountService($this->db))->requestPasswordReset($email, $ip);
        return $id;
    }

    /** @param list<string>|mixed $roles */
    public function updateRoles(int $userId, mixed $roles, int $actorId): void
    {
        if ($userId === $actorId) {
            throw ValidationException::withMessage('You cannot change your own roles. Ask another administrator.');
        }
        $roles = $this->validRoles($roles, $actorId);
        $this->db->transaction(function () use ($userId, $roles, $actorId) {
            $u = $this->db->one('SELECT * FROM users WHERE id = ? AND is_staff = 1 FOR UPDATE', [$userId]) ?? throw ValidationException::withMessage('Staff member not found.');
            $before = $this->rolesOf($userId);
            $touchesSuper = in_array('super_admin', $before, true) !== in_array('super_admin', $roles, true);
            if ($touchesSuper && !in_array('super_admin', $this->rolesOf($actorId), true)) {
                throw ValidationException::withMessage('Only a super administrator can grant or remove super administrator access.');
            }
            if (in_array('super_admin', $before, true) && !in_array('super_admin', $roles, true)) {
                $this->assertNotLastSuperAdmin($userId);
            }
            $this->replaceRoles($userId, $roles);
            // Role changes take effect on the next request (permissions are read per request).
            AuditLogger::log('staff.roles_changed', 'user', $userId, ['roles' => $before], ['roles' => $roles], $actorId);
        });
    }

    public function setActive(int $userId, bool $active, int $actorId): void
    {
        if ($userId === $actorId) {
            throw ValidationException::withMessage('You cannot suspend your own account.');
        }
        $this->db->transaction(function () use ($userId, $active, $actorId) {
            $u = $this->db->one('SELECT * FROM users WHERE id = ? AND is_staff = 1 FOR UPDATE', [$userId]) ?? throw ValidationException::withMessage('Staff member not found.');
            if (!$active && in_array('super_admin', $this->rolesOf($userId), true)) {
                $this->assertNotLastSuperAdmin($userId);
            }
            // Suspension also invalidates every existing session of that user immediately.
            $this->db->exec('UPDATE users SET status = ?, password_changed_at = IF(? = 0, NOW(), password_changed_at) WHERE id = ?',
                [$active ? 'active' : 'suspended', $active ? 1 : 0, $userId]);
            AuditLogger::log($active ? 'staff.reactivated' : 'staff.suspended', 'user', $userId, ['status' => $u['status']], ['status' => $active ? 'active' : 'suspended'], $actorId);
        });
    }

    /** Removes staff access entirely (the account remains as a customer account; history is kept). */
    public function revoke(int $userId, int $actorId): void
    {
        if ($userId === $actorId) {
            throw ValidationException::withMessage('You cannot remove your own staff access.');
        }
        $this->db->transaction(function () use ($userId, $actorId) {
            $this->db->one('SELECT id FROM users WHERE id = ? AND is_staff = 1 FOR UPDATE', [$userId]) ?? throw ValidationException::withMessage('Staff member not found.');
            $before = $this->rolesOf($userId);
            if (in_array('super_admin', $before, true)) {
                if (!in_array('super_admin', $this->rolesOf($actorId), true)) {
                    throw ValidationException::withMessage('Only a super administrator can remove another super administrator.');
                }
                $this->assertNotLastSuperAdmin($userId);
            }
            $this->db->exec('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
            $this->db->exec('UPDATE users SET is_staff = 0, password_changed_at = NOW() WHERE id = ?', [$userId]);
            AuditLogger::log('staff.revoked', 'user', $userId, ['roles' => $before], ['roles' => []], $actorId);
        });
    }

    private function assertNotLastSuperAdmin(int $userId): void
    {
        $others = (int) $this->db->value(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.is_staff = 1 AND u.id <> ?",
            [$userId]
        );
        if ($others === 0) {
            throw ValidationException::withMessage('This is the last active super administrator. Add another super administrator first.');
        }
    }

    /** @return list<string> */
    private function validRoles(mixed $roles, int $actorId): array
    {
        $roles = array_values(array_unique(array_filter((array) $roles, 'is_string')));
        $known = array_column($this->db->all('SELECT slug FROM roles'), 'slug');
        if (!$roles || array_diff($roles, $known)) {
            throw new ValidationException(['roles' => 'Choose at least one valid role.']);
        }
        if (in_array('super_admin', $roles, true) && !in_array('super_admin', $this->rolesOf($actorId), true)) {
            throw new ValidationException(['roles' => 'Only a super administrator can grant super administrator access.']);
        }
        return $roles;
    }

    /** @param list<string> $roles */
    private function replaceRoles(int $userId, array $roles): void
    {
        $this->db->exec('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        foreach ($roles as $slug) {
            $this->db->exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$userId, $slug]);
        }
        Auth::reset();
    }
}
