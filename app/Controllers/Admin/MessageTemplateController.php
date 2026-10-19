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
