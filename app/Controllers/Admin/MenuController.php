<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\SiteContentService;

/** Admin → Content → Menus (content.manage): the links and short items shown in fixed places on the site. */
final class MenuController extends AdminController
{
    public function index(Request $req): Response
    {
        return $this->view('content/menus/index', ['title' => 'Menus', 'rows' => (new SiteContentService())->menus()]);
    }

    public function show(Request $req): Response
    {
        $svc = new SiteContentService();
        $m = $svc->menuBySlug($this->slug($req));
        $editId = (int) ($req->query['edit'] ?? 0);
        $items = $svc->menuItems((int) $m['id']);
        $editing = $editId ? (current(array_filter($items, fn ($i) => (int) $i['id'] === $editId)) ?: throw HttpException::notFound()) : null;
        return $this->view('content/menus/show', ['title' => 'Menu: ' . brand_text((string) $m['title']), 'm' => $m, 'items' => $items, 'editing' => $editing]);
    }

    public function saveItem(Request $req): Response
    {
        $slug = $this->slug($req);
        $item = $this->intParam($req, 'item') ?: null;
        (new SiteContentService())->saveMenuItem($slug, $item, $req->post, $this->actor());
        return $this->success($item ? 'Item saved.' : 'Item added.', "/admin/content/menus/$slug");
    }

    public function deleteItem(Request $req): Response
    {
        $slug = $this->slug($req);
        (new SiteContentService())->deleteMenuItem($slug, $this->intParam($req, 'item'), $this->actor());
        return $this->success('Item removed.', "/admin/content/menus/$slug");
    }

    public function move(Request $req): Response
    {
        $slug = $this->slug($req);
        (new SiteContentService())->moveMenuItem($slug, $this->intParam($req, 'item'), (string) $req->input('direction', '') === 'up' ? 'up' : 'down', $this->actor());
        return Response::redirect("/admin/content/menus/$slug");
    }

    public function saveTitle(Request $req): Response
    {
        $slug = $this->slug($req);
        (new SiteContentService())->saveMenuTitle($slug, (string) $req->input('menu_title', ''), $this->actor());
        return $this->success('Heading saved.', "/admin/content/menus/$slug");
    }

    private function slug(Request $req): string
    {
        $s = (string) $req->param('menu');
        return preg_match('/^[a-z0-9_]{1,40}$/', $s) ? $s : throw HttpException::notFound();
    }
}
