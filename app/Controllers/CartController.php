<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\ValidationException;
use Waimart\Services\CartService;

final class CartController extends Controller
{
    public function show(Request $req): Response
    {
        \Waimart\Services\ExpiryService::maybeSweep();   // fallback if the scheduler is not running (C8)
        return $this->view('cart/index', ['title' => 'Cart', 'cart' => CartService::current()->summary()])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function add(Request $req): Response
    {
        $cart = CartService::current();
        try {
            $result = $cart->add($this->int($req, 'variant_id'), $this->int($req, 'quantity', 1));
        } catch (ValidationException $e) {
            if ($req->isAjax()) {
                return Response::json(['ok' => false, 'message' => $e->errors['_form'] ?? $e->errors['quantity'] ?? $e->getMessage()], 422);
            }
            throw $e;
        }
        if ($req->isAjax()) {
            return Response::json(['ok' => true, 'message' => $result['message'], 'count' => $cart->count()]);
        }
        Session::flash($result['added'] > 0 ? 'success' : 'warning', $result['message']);
        return Response::back($req, [], '/cart');
    }

    public function update(Request $req): Response
    {
        $qty = CartService::current()->setQuantity($this->int($req, 'variant_id'), $this->int($req, 'quantity', 0));
        Session::flash('success', $qty === 0 ? 'Item removed from your cart.' : 'Cart updated.');
        return Response::redirect('/cart');
    }

    public function remove(Request $req): Response
    {
        CartService::current()->remove($this->int($req, 'variant_id'));
        Session::flash('success', 'Item removed from your cart.');
        return Response::redirect('/cart');
    }

    private function int(Request $req, string $key, int $default = 0): int
    {
        $v = (string) ($req->post[$key] ?? '');
        return preg_match('/^\d{1,6}$/', $v) ? (int) $v : $default;
    }
}
