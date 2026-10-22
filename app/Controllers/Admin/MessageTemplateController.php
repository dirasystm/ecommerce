<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\MessageTemplates;

/** Admin → Content & marketing → Messages (messages.manage): the wording of customer and seller emails and SMS. */
final class MessageTemplateController extends AdminController
{
    public function index(Request $req): Response
    {
        return $this->view('messages/index', ['title' => 'Messages', 'rows' => (new MessageTemplates())->all()]);
    }

    public function edit(Request $req): Response
    {
        $code = $this->code($req);
        $svc = new MessageTemplates();
        return $this->view('messages/edit', [
            'title' => $svc->get($code)['name'] . ' — Messages', 't' => $svc->get($code), 'vars' => MessageTemplates::variables($code),
            'required' => MessageTemplates::TEMPLATES[$code][8], 'preview' => $svc->preview($code),
        ]);
    }

    /** The designed email for this template, with sample values (and the latest order, for order messages). */
    public function emailPreview(Request $req): Response
    {
        $code = $this->code($req);
        $p = (new MessageTemplates())->preview($code);
        if ($p['email'] === null) {
            return Response::html('<p style="font-family:sans-serif;padding:24px">This message has no email version (or it is switched off).</p>');
        }
        $orderId = (int) \Waimart\Core\App::db()->value('SELECT id FROM orders ORDER BY id DESC LIMIT 1');
        $html = \Waimart\Services\EmailLayout::render((string) $p['subject'], $p['email'], $code, ['order_id' => $orderId ?: null, 'codes' => ['483920']]);
        return Response::html(\Waimart\Services\EmailLayout::forBrowser($html))->withHeader('Cache-Control', 'no-store, private')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function update(Request $req): Response
    {
        $code = $this->code($req);
        (new MessageTemplates())->save($code, $req->post, $this->actor());
        return $this->success('Message saved. It is used from the next message sent.', "/admin/messages/$code");
    }

    public function restore(Request $req): Response
    {
        $code = $this->code($req);
        (new MessageTemplates())->restore($code, $this->actor());
        return $this->success('The original wording is back.', "/admin/messages/$code");
    }

    private function code(Request $req): string
    {
        $c = (string) $req->param('code');
        return isset(MessageTemplates::TEMPLATES[$c]) ? $c : throw HttpException::notFound();
    }
}
