<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\AccountService;
use Waimart\Services\DeliveryService;

/** Saved delivery addresses. Other customers' addresses are indistinguishable from missing ones (404). */
final class AddressController extends Controller
{
    public function index(Request $req): Response
    {
        return $this->view('account/addresses', [
            'title' => 'Addresses', 'section' => 'addresses',
            'addresses' => (new AccountService())->addresses((int) Auth::id()),
        ]);
    }

    public function create(Request $req): Response
    {
        return $this->form(null);
    }

    public function edit(Request $req): Response
    {
        $addr = (new AccountService())->address((int) Auth::id(), (int) $req->param('id')) ?? throw HttpException::notFound();
        return $this->form($addr);
    }

    public function store(Request $req): Response
    {
        (new AccountService())->saveAddress((int) Auth::id(), null, $req->post);
        Session::flash('success', 'Address saved.');
        return Response::redirect('/account/addresses');
    }

    public function update(Request $req): Response
    {
        $this->owned($req);
        (new AccountService())->saveAddress((int) Auth::id(), (int) $req->param('id'), $req->post);
        Session::flash('success', 'Address updated.');
        return Response::redirect('/account/addresses');
    }

    public function delete(Request $req): Response
    {
        $this->owned($req);
        (new AccountService())->deleteAddress((int) Auth::id(), (int) $req->param('id'));
        Session::flash('success', 'Address removed.');
        return Response::redirect('/account/addresses');
    }

    public function makeDefault(Request $req): Response
    {
        $this->owned($req);
        (new AccountService())->setDefaultAddress((int) Auth::id(), (int) $req->param('id'));
        Session::flash('success', 'Default address updated.');
        return Response::redirect('/account/addresses');
    }

    private function owned(Request $req): void
    {
        if ((new AccountService())->address((int) Auth::id(), (int) $req->param('id')) === null) {
            throw HttpException::notFound();
        }
    }

    private function form(?array $addr): Response
    {
        return $this->view('account/address-form', [
            'title' => $addr ? 'Edit address' : 'Add address', 'section' => 'addresses',
            'addr' => $addr, 'counties' => (new DeliveryService())->counties(),
        ]);
    }
}
