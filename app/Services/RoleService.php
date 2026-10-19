<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;

/**
 * Roles and their permissions (Admin → Staff & roles → Roles; roles.manage). Deny by default: a person can do only
 * what one of their roles, or a permission given to them personally, allows, and every protected route and action
 * checks it on the server.
 *
 * No self-escalation:
 *   - the Super Administrator role always has every permission and cannot be edited or deleted;
 *   - you can only put into a role permissions you hold yourself;
 *   - you cannot edit a role you hold (ask another administrator), unless you are a super administrator;
 *   - built-in roles cannot be deleted; a custom role can be deleted once nobody has it.
 */
final class RoleService
{
    /** How the permission matrix is grouped: permission prefix => section. */
    public const SECTIONS = [
        'dashboard' => 'Overview', 'reports' => 'Overview', 'audit' => 'Overview',
        'orders' => 'Orders & fulfilment', 'delivery' => 'Orders & fulfilment', 'pickup_points' => 'Orders & fulfilment', 'cod' => 'Orders & fulfilment',
        'payments' => 'Payments & money', 'refunds' => 'Payments & money', 'resolutions' => 'Payments & money', 'payouts' => 'Payments & money', 'commissions' => 'Payments & money',
        'customers' => 'Customers & service', 'returns' => 'Customers & service', 'reviews' => 'Customers & service', 'disputes' => 'Customers & service',
        'catalogue' => 'Catalogue & stock', 'inventory' => 'Catalogue & stock', 'products' => 'Catalogue & stock',
        'sellers' => 'Marketplace',
        'content' => 'Content & marketing', 'coupons' => 'Content & marketing', 'messages' => 'Content & marketing',
        'settings' => 'Administration', 'users' => 'Administration', 'roles' => 'Administration', 'secrets' => 'Administration',
    ];

    /** Permissions that change who can do what, or touch money destinations and credentials: highlighted in the matrix. */
    public const SENSITIVE = ['users.manage', 'roles.manage', 'settings.manage', 'secrets.manage', 'payments.configure', 'commissions.manage', 'payouts.manage', 'audit.view'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,array<string,string>> section => [slug => description], in a stable order */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT slug, description FROM permissions ORDER BY slug') as $p) {
            $out[self::SECTIONS[explode('.', $p['slug'])[0]] ?? 'Other'][$p['slug']] = $p['description'];
        }
        $order = array_flip(array_values(array_unique(array_values(self::SECTIONS))));
        uksort($out, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        return $out;
    }

    /** @return list<array<string,mixed>> roles with their permissions and how many staff have them */
    public function roles(): array
    {
        $rows = $this->db->all(
            "SELECT r.*, (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id AND u.is_staff = 1 WHERE ur.role_id = r.id) AS people
               FROM roles r ORDER BY r.is_system DESC, r.id"
        );
        foreach ($rows as &$r) {
            $r['permissions'] = $this->permissionsOf((int) $r['id']);
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function role(int $id): array
    {
        $r = $this->db->one('SELECT * FROM roles WHERE id = ?', [$id]) ?? throw new \Waimart\Core\HttpException(404, 'Role not found.');
        $r['permissions'] = $this->permissionsOf($id);
        return $r;
    }

    /** @return list<string> */
    public function permissionsOf(int $roleId): array
    {
        $slug = $this->db->value('SELECT slug FROM roles WHERE id = ?', [$roleId]);
        if ($slug === 'super_admin') {
            return array_column($this->db->all('SELECT slug FROM permissions ORDER BY slug'), 'slug');
        }
        return array_column($this->db->all(
            'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.slug', [$roleId]
        ), 'slug');
    }

    /** Why the actor may not edit this role, or null. @param array<string,mixed> $role */
    public function editBlockedReason(array $role, int $actorId): ?string
    {
        if ($role['slug'] === 'super_admin') {
            return 'The Super Administrator role always has every permission and cannot be changed.';
        }
        $actorRoles = (new StaffService($this->db))->rolesOf($actorId);
        if (!in_array('super_admin', $actorRoles, true) && in_array($role['slug'], $actorRoles, true)) {
            return 'You have this role yourself, so another administrator must change it.';
        }
        return null;
    }

    /** @param array<string,mixed> $in name, description, permissions[] */
    public function create(array $in, int $actorId): int
    {
        [$name, $desc, $perms] = $this->validated($in, $actorId, []);
        $slug = $this->uniqueSlug($name);
        $id = $this->db->transaction(function () use ($slug, $name, $desc, $perms, $actorId) {
            $id = $this->db->insert('roles', ['slug' => $slug, 'name' => $name, 'description' => $desc, 'is_system' => 0]);
            $this->writePermissions($id, $perms);
            AuditLogger::log('role.created', 'role', $id, null, ['name' => $name, 'permissions' => $perms], $actorId);
            return $id;
        });
        Auth::reset();
        return $id;
    }

    /** @param array<string,mixed> $in */
    public function update(int $id, array $in, int $actorId): void
    {
        $role = $this->role($id);
        if (($why = $this->editBlockedReason($role, $actorId)) !== null) {
            throw ValidationException::withMessage($why);
        }
        [$name, $desc, $perms] = $this->validated($in, $actorId, $role['permissions'], $id);
        $this->db->transaction(function () use ($id, $role, $name, $desc, $perms, $actorId) {
            $this->db->exec('UPDATE roles SET name = ?, description = ? WHERE id = ?', [$name, $desc, $id]);
            $this->writePermissions($id, $perms);
            $added = array_values(array_diff($perms, $role['permissions']));
            $removed = array_values(array_diff($role['permissions'], $perms));
            AuditLogger::log('role.updated', 'role', $id, ['name' => $role['name'], 'permissions' => $role['permissions']],
                ['name' => $name, 'permissions' => $perms, 'added' => $added, 'removed' => $removed], $actorId);
        });
        Auth::reset();   // everyone with the role gets the change on their next request (permissions are read per request)
    }

    public function delete(int $id, int $actorId): void
    {
        $role = $this->role($id);
        if ((int) $role['is_system'] === 1) {
            throw ValidationException::withMessage('Built-in roles cannot be deleted. You can change their permissions instead.');
        }
        if (($why = $this->editBlockedReason($role, $actorId)) !== null) {
            throw ValidationException::withMessage($why);
        }
        $people = (int) $this->db->value('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$id]);
        if ($people > 0) {
            throw ValidationException::withMessage("$people " . ($people === 1 ? 'person has' : 'people have') . ' this role. Give them another role first.');
        }
        $this->db->transaction(function () use ($id, $role, $actorId) {
            $this->db->exec('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            $this->db->exec('DELETE FROM roles WHERE id = ?', [$id]);
            AuditLogger::log('role.deleted', 'role', $id, ['name' => $role['name'], 'permissions' => $role['permissions']], null, $actorId);
        });
    }

    /**
     * Name, description and the permissions to store. Permissions the actor does not hold may stay in a role they
     * were already in (an administrator can edit wording without losing them), but cannot be added.
     * @param list<string> $current @return array{0:string,1:?string,2:list<string>}
     */
    private function validated(array $in, int $actorId, array $current, ?int $roleId = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $desc = trim((string) ($in['description'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Give the role a name (up to 100 characters).';
        } elseif ($this->db->value('SELECT 1 FROM roles WHERE name = ? AND id <> ?', [$name, $roleId ?? 0])) {
            $errors['name'] = 'Another role already has this name.';
        }
        if (mb_strlen($desc) > 255) {
            $errors['description'] = 'Keep the description under 255 characters.';
        }
        $known = array_column($this->db->all('SELECT slug FROM permissions'), 'slug');
        $perms = array_values(array_unique(array_filter((array) ($in['permissions'] ?? []), 'is_string')));
        if (array_diff($perms, $known)) {
            $errors['permissions'] = 'Unknown permission.';
        }
        $mine = Auth::permissionsFor($actorId);
        $notMine = array_values(array_diff(array_diff($perms, $current), $mine));
        if ($notMine) {
            $errors['permissions'] = 'You can only give permissions you have yourself: ' . implode(', ', $notMine) . '.';
        }
        if (!$perms && !isset($errors['permissions'])) {
            $errors['permissions'] = 'Choose at least one permission.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        sort($perms);
        return [$name, $desc !== '' ? $desc : null, $perms];
    }

    /** @param list<string> $perms */
    private function writePermissions(int $roleId, array $perms): void
    {
        $this->db->exec('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        foreach ($perms as $slug) {
            $this->db->exec('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE slug = ?', [$roleId, $slug]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'role';
        $base = mb_substr($base, 0, 40);
        $slug = $base;
        for ($i = 2; $this->db->value('SELECT 1 FROM roles WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '_' . $i;
        }
        return $slug;
    }
}
