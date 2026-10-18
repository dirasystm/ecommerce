<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Repositories\OrderRepository;
use Waimart\Services\DisputeService;
use Waimart\Services\OrderAccess;

/**
 * Customer side of seller disputes ("Report a problem"). Same access as returns: the order's owner or a verified
 * guest. A case is only ever loaded through its order, so another order's case number gives 404.
 */
final class DisputeController extends Controller
{
    public function form(Request $req): Response
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        $parts = (new DisputeService())->disputableParts($order);
        if (!$parts) {
            throw \Waimart\Core\ValidationException::withMessage('A problem can no longer be reported online for this order. Please contact customer care.');
        }
        return $this->view('orders/problem', ['title' => 'Report a problem', 'order' => $order, 'parts' => $parts, 'reasons' => DisputeService::REASONS]);
    }

    public function open(Request $req): Response
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        $so = (string) $req->input('seller_order_id', '');
        $allowed = array_map('intval', array_column((new DisputeService())->disputableParts($order), 'id'));
        if (!ctype_digit($so) || !in_array((int) $so, $allowed, true)) {
            throw new \Waimart\Core\ValidationException(['seller_order_id' => 'Choose which seller\'s items the problem is with.']);
        }
        $d = (new DisputeService())->open((int) $so, 'customer', Auth::id(), (string) $req->input('reason', ''), (string) $req->input('details', ''));
        Session::flash('success', "Case {$d['dispute_number']} opened. We will look into it and keep you updated here and by message.");
        return Response::redirect('/order/' . $order['order_number'] . '/cases/' . $d['id']);
    }

    public function show(Request $req): Response
    {
        [$order, $d] = $this->visibleCase($req);
        return $this->view('orders/case', ['title' => 'Case ' . $d['dispute_number'], 'order' => $order, 'd' => $d,
            'messages' => (new DisputeService())->messages((int) $d['id'], false)]);
    }

    public function message(Request $req): Response
    {
        [$order, $d] = $this->visibleCase($req);
        (new DisputeService())->message((int) $d['id'], 'customer', Auth::id(), (string) $req->input('body', ''));
        Session::flash('success', 'Message sent.');
        return Response::redirect('/order/' . $order['order_number'] . '/cases/' . $d['id']);
    }

    public function withdraw(Request $req): Response
    {
        [$order, $d] = $this->visibleCase($req);
        (new DisputeService())->withdraw((int) $d['id'], (int) $order['id']);
        Session::flash('success', 'Case withdrawn.');
        return Response::redirect('/order/' . $order['order_number'] . '/cases/' . $d['id']);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function visibleCase(Request $req): array
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        $id = (string) $req->param('id');
        $d = ctype_digit($id) ? (new DisputeService())->find((int) $id) : throw HttpException::notFound();
        if ((int) $d['order_id'] !== (int) $order['id']) {
            throw HttpException::notFound();
        }
        return [$order, $d];
    }

    private function visibleOrder(string $number): array
    {
        $order = (new OrderRepository(App::db()))->byNumber($number);
        if ($order === null || !OrderAccess::canView($order)) {
            throw HttpException::notFound('We could not find that order.');
        }
        return $order;
    }
}
