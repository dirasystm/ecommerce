<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\Marketing\CampaignService;

/**
 * /go/{code}: promotional links. Visitors are counted and sent on to a page of this shop (only ever an internal path);
 * link-preview crawlers get the campaign's share preview; disabled or expired links open the homepage, uncounted.
 */
final class GoController extends Controller
{
    public function go(Request $req): Response
    {
        $svc = new CampaignService();
        ['link' => $link, 'live' => $live] = $svc->resolve((string) $req->param('code'));
        if ($link === null || !$live) {
            return Response::redirect('/')->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex');
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $svc->click($link, $ua, (string) ($_SERVER['HTTP_REFERER'] ?? ''), (string) ($req->query['via'] ?? 'link'));
        $target = CampaignService::landingUrl($link);
        if (preg_match(CampaignService::PREVIEW_UA, $ua)) {
            return Response::html($this->preview($link, $svc->creative($link), $target))->withHeader('X-Robots-Tag', 'noindex');
        }
        return (new Response('', 302, ['Location' => $target]))->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** Open Graph / Twitter card page for social previews (absolute URLs, the campaign's own wording and image). */
    private function preview(array $link, array $c, string $target): string
    {
        $canonical = rtrim((string) config('app.url'), '/') . substr($target, strlen(rtrim((string) config('app.base_path', ''), '/')));
        $desc = (string) ($link['description'] ?: \Waimart\Services\Brand::tagline());
        $meta = fn (string $k, string $v, string $attr = 'property') => '<meta ' . $attr . '="' . e($k) . '" content="' . e($v) . '">';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . e((string) $link['headline']) . '</title>'
            . $meta('og:type', 'website') . $meta('og:site_name', brand_name()) . $meta('og:title', (string) $link['headline'])
            . $meta('og:description', $desc) . $meta('og:image', (string) $c['image_abs']) . $meta('og:url', $canonical)
            . $meta('twitter:card', 'summary_large_image', 'name') . $meta('twitter:title', (string) $link['headline'], 'name')
            . $meta('twitter:description', $desc, 'name') . $meta('twitter:image', (string) $c['image_abs'], 'name')
            . '<link rel="canonical" href="' . e($canonical) . '"><meta http-equiv="refresh" content="0;url=' . e($canonical) . '"></head>'
            . '<body><a href="' . e($canonical) . '">' . e((string) $link['headline']) . '</a></body></html>';
    }
}
