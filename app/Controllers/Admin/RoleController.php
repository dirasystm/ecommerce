<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\RoleService;

/** Admin → Staff & roles → Roles (roles.manage): the permission matrix, and custom roles. */
final class RoleController extends AdminController
{
    public function index(Request $req): Response
    {
        $svc = new RoleService();
        return $this->view('roles/index', ['title' => 'Roles', 'roles' => $svc->roles(), 'catalog' => $svc->catalog()]);
    }

    public function create(Request $req): Response
    {
        return $this->form(null);
    }

    public function store(Request $req): Response
    {
        $id = (new RoleService())->create($req->post, $this->actor());
        return $this->success('Role created. Give it to staff members from their page.', "/admin/roles/$id");
    }

    public function edit(Request $req): Response
    {
        return $this->form((new RoleService())->role($this->intParam($req, 'id')));
    }

    public function update(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new RoleService())->update($id, $req->post, $this->actor());
        return $this->success('Role saved. Everyone with this role has the new permissions from their next click.', "/admin/roles/$id");
    }

    public function delete(Request $req): Response
    {
        (new RoleService())->delete($this->intParam($req, 'id'), $this->actor());
        return $this->success('Role deleted.', '/admin/roles');
    }

    /** @param array<string,mixed>|null $role */
    private function form(?array $role): Response
    {
        $svc = new RoleService();
        return $this->view('roles/form', [
            'title' => $role ? $role['name'] . ' — Roles' : 'New role', 'role' => $role, 'catalog' => $svc->catalog(),
            'mine' => Auth::permissionsFor($this->actor()), 'blocked' => $role ? $svc->editBlockedReason($role, $this->actor()) : null,
        ]);
    }
}
