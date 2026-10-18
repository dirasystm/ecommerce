<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\SiteContentService;

/** Admin → Content → Homepage (content.manage): the homepage's sections, in order. */
final class HomepageController extends AdminController
{
    public function index(Request $req): Response
    {
        return $this->view('content/home/index', ['title' => 'Homepage', 'rows' => (new SiteContentService())->sections()]);
    }

    public function create(Request $req): Response
    {
        $type = (string) ($req->query['type'] ?? 'text');
        $type = in_array($type, SiteContentService::REPEATABLE_SECTIONS, true) ? $type : 'text';
        return $this->view('content/home/edit', ['title' => 'New homepage section', 's' => ['type' => $type], 'categories' => $this->categories()]);
    }

    public function edit(Request $req): Response
    {
        $s = (new SiteContentService())->section($this->intParam($req, 'id'));
        return $this->view('content/home/edit', ['title' => 'Edit homepage section', 's' => $s, 'categories' => $this->categories()]);
    }

    public function save(Request $req): Response
    {
        (new SiteContentService())->saveSection($this->intParam($req, 'id') ?: null, $req->post, $this->actor());
        return $this->success('Homepage section saved.', '/admin/content/homepage');
    }

    public function move(Request $req): Response
    {
        (new SiteContentService())->moveSection($this->intParam($req, 'id'), (string) $req->input('direction', '') === 'up' ? 'up' : 'down', $this->actor());
        return Response::redirect('/admin/content/homepage');
    }

    public function delete(Request $req): Response
    {
        (new SiteContentService())->deleteSection($this->intParam($req, 'id'), $this->actor());
        return $this->success('Section removed.', '/admin/content/homepage');
    }

    /** @return list<array<string,mixed>> */
    private function categories(): array
    {
        return App::db()->all('SELECT c.id, c.name, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.is_active = 1 ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name');
    }
}
