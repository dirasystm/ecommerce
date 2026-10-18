<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\ContentRenderer;
use Waimart\Services\SiteContentService;

/**
 * Information and legal pages from the database (Admin → Content → Pages). Built-in pages keep their addresses
 * (/help, /terms …); pages added by staff live under /pages/{slug}. Drafts and scheduled pages are visible only to
 * staff with content.manage (preview).
 */
final class PageController extends Controller
{
    public function show(Request $req): Response
    {
        return $this->render((string) $req->param('page'));
    }

    /** /pages/{slug}: pages created in the admin (built-in pages answer only at their own address). */
    public function custom(Request $req): Response
    {
        $slug = (string) $req->param('slug');
        if (in_array($slug, SiteContentService::SYSTEM_PAGES, true)) {
            return Response::redirect('/' . $slug, 301);
        }
        return $this->render($slug);
    }

    private function render(string $slug): Response
    {
        $svc = new SiteContentService();
        $page = $svc->livePage($slug);
        $preview = false;
        if ($page === null && Auth::can('content.manage')) {
            $page = \Waimart\Core\App::db()->one('SELECT * FROM content_pages WHERE slug = ?', [$slug]);
            $preview = $page !== null;
        }
        if ($page === null) {
            throw HttpException::notFound();
        }
        $title = ContentRenderer::plain((string) $page['title']);
        return $this->view('pages/show', [
            'title' => $title,
            'metaDescription' => $page['meta_description'] !== null ? ContentRenderer::plain((string) $page['meta_description']) : null,
            'slug' => $slug,
            'body' => (new ContentRenderer())->render((string) $page['body']),
            'nav' => $svc->navPages(),
            'preview' => $preview,
        ]);
    }
}
