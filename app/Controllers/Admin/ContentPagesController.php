<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\ContentRenderer;
use Waimart\Services\SiteContentService;

/** Admin → Content → Pages (content.manage). */
final class ContentPagesController extends AdminController
{
    public function index(Request $req): Response
    {
        $lp = \Waimart\Core\ListParams::from($req, ['default' => 'p.is_system DESC, p.sort_order', 'title' => 'p.title', 'updated' => 'p.updated_at'], 'default', 'asc');
        $status = (string) ($req->query['status'] ?? '');
        $where = ['1=1'];
        $params = [];
        if ($lp->q !== '') {
            $where[] = '(p.title LIKE ? OR p.slug LIKE ?)';
            array_push($params, $lp->like(), $lp->like());
        }
        if (in_array($status, ['published', 'draft'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        $rows = $lp->fetch(\Waimart\Core\App::db(), 'SELECT p.*, u.first_name AS editor',
            'FROM content_pages p LEFT JOIN users u ON u.id = p.updated_by WHERE ' . implode(' AND ', $where), $params, 'p.title');
        return $this->view('content/pages/index', ['title' => 'Pages', 'rows' => $rows, 'lp' => $lp, 'status' => $status]);
    }

    public function create(Request $req): Response
    {
        return $this->view('content/pages/edit', ['title' => 'New page', 'p' => null, 'revisions' => []]);
    }

    public function edit(Request $req): Response
    {
        $svc = new SiteContentService();
        $p = $svc->page($this->intParam($req, 'id'));
        return $this->view('content/pages/edit', ['title' => 'Edit ' . ContentRenderer::plain((string) $p['title']), 'p' => $p, 'revisions' => $svc->revisions((int) $p['id'])]);
    }

    public function save(Request $req): Response
    {
        $id = $this->intParam($req, 'id') ?: null;
        $id = (new SiteContentService())->savePage($id, $req->post, $this->actor());
        return $this->success('Page saved.', "/admin/content/pages/$id");
    }

    public function delete(Request $req): Response
    {
        (new SiteContentService())->deletePage($this->intParam($req, 'id'), $this->actor());
        return $this->success('Page deleted.', '/admin/content/pages');
    }

    public function restore(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new SiteContentService())->restoreRevision($id, $this->intParam($req, 'rid'), $this->actor());
        return $this->success('The earlier version is back. (The version it replaced was kept, so you can undo this too.)', "/admin/content/pages/$id");
    }
}
