<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Auth;
use Waimart\Core\Crypto;
use Waimart\Core\Money;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\ValidationException;
use Waimart\Services\AccountService;
use Waimart\Services\CartService;
use Waimart\Services\CheckoutService;
use Waimart\Services\CouponService;
use Waimart\Services\DeliveryService;
use Waimart\Services\MarketplacePolicy;
use Waimart\Services\OrderAccess;
use Waimart\Services\OrderService;
use Waimart\Services\PaymentOptions;

/**
 * Checkout for guests and registered customers (D1, D2, B1–B3).
 * Phase 5: validates and prices everything server-side and shows a review.
 * Phase 6 turns the final step into transactional order creation + stock reservation.
 */
final class CheckoutController extends Controller
{
    public function show(Request $req): Response
    {
        \Waimart\Services\ExpiryService::maybeSweep();   // fallback if the scheduler is not running (C8)
        CartService::current()->activity();      // still shopping: cart reminders wait
        $cart = CartService::current()->summary();
        if ($cart['is_empty']) {
            Session::flash('info', 'Your cart is empty.');
            return Response::redirect('/cart');
        }
        if ($cart['has_issues']) {
            Session::flash('warning', 'Some items in your cart changed. Please review them before checking out.');
            return Response::redirect('/cart');
        }
        $delivery = new DeliveryService();
        $user = Auth::user();
        \Waimart\Services\Marketing\Tracker::record('checkout_started', []);
        // One-time key for duplicate-submission protection (used by order placement in Phase 6).
        if (!Session::get('checkout_token')) {
            Session::set('checkout_token', Crypto::token(32));
        }
        return $this->view('checkout/index', [
            'title' => 'Checkout',
            'cart' => $cart,
            'user' => $user,
            'addresses' => $user ? (new AccountService())->addresses((int) $user['id']) : [],
            'counties' => $delivery->counties(),
            'pickupPoints' => $delivery->pickupPoints(),
            'deliveryAvailable' => $delivery->deliveryAvailable(),
            'pickupAvailable' => $delivery->pickupAvailable(),
            'payments' => PaymentOptions::forFulfilment(null, null, $cart['subtotal']),
            'deliveryNote' => (string) \Waimart\Core\Settings::get('delivery_checkout_note', ''),
            'pickupNote' => (string) \Waimart\Core\Settings::get('pickup_checkout_note', ''),
            'checkoutToken' => (string) Session::get('checkout_token'),
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    /** Live fee/total preview while the customer fills the form. Display only; submit re-validates. */
    public function quote(Request $req): Response
    {
        $cart = CartService::current()->summary();
        $delivery = new DeliveryService();
        $method = (string) $req->input('fulfilment_method', '');
        $out = ['ok' => false, 'message' => null, 'fee' => null, 'eta' => null, 'cod_allowed' => null];

        if (in_array($method, ['delivery', 'pickup'], true) && !DeliveryService::methodEnabled($method)) {
            $method = '';
        }
        if ($method === 'delivery') {
            $county = (string) $req->input('county_id', '');
            $town = (string) $req->input('town', '');
            $addressId = (string) $req->input('address_id', '');
            if ($addressId !== '' && $addressId !== 'new' && Auth::check()) {
                $addr = (new AccountService())->address((int) Auth::id(), (int) $addressId);
                [$county, $town] = $addr ? [(string) $addr['county_id'], (string) $addr['town']] : ['', ''];
            }
            if (ctype_digit($county) && trim($town) !== '') {
                $q = $delivery->quoteDelivery((int) $county, $town, $cart['subtotal']);
                $f = $q === null ? null : MarketplacePolicy::fee(fn (string $s) => $delivery->quoteDelivery((int) $county, $town, $s), $cart['lines'], $cart['subtotal']);
                if ($q === null || $f === null) {
                    $hint = $delivery->pickupHint((int) $county);
                    $out['message'] = 'We do not deliver to this location yet.' . ($hint !== '' ? $hint : ' Please choose a pickup station.');
                    $out['cod_allowed'] = false;
                } else {
                    $out = ['ok' => true, 'fee' => $f['fee'], 'eta' => $q['eta_min'] . '–' . $q['eta_max'] . ' business days (estimate)',
                            'cod_allowed' => $q['cod_allowed'], 'message' => $f['is_free'] ? 'Free delivery applied.'
                                : ($f['shipments'] > 1 ? "Delivery is charged per seller ({$f['shipments']} sellers in this order)." : null)];
                }
            }
        } elseif ($method === 'pickup') {
            $id = (string) $req->input('pickup_point_id', '');
            $q = ctype_digit($id) ? $delivery->quotePickup((int) $id, $cart['subtotal']) : null;
            if ($q !== null && $q['error'] === null) {
                $f = MarketplacePolicy::fee(function (string $s) use ($delivery, $id) {
                    $p = $delivery->quotePickup((int) $id, $s);
                    return $p === null || $p['error'] !== null ? null : ['fee' => $p['fee'], 'is_free' => Money::isZero($p['fee'])];
                }, $cart['lines'], $cart['subtotal']);
                $out = ['ok' => true, 'fee' => $f['fee'] ?? $q['fee'], 'eta' => 'We will notify you when it is ready for collection', 'cod_allowed' => $q['cod_allowed'], 'message' => null];
            } elseif ($q !== null) {
                $out['message'] = $q['error'];
            }
        }
        $fee = $out['fee'] ?? '0.00';
        // Marketplace COD rule on top of the location's own rule (same check as order placement).
        $marketCod = MarketplacePolicy::cod($cart['lines'], $method);
        if ($out['cod_allowed'] !== false && !$marketCod['allowed']) {
            $out['cod_allowed'] = false;
        }
        $out['cod_reason'] = $out['cod_allowed'] === false ? ($marketCod['reason'] ?? 'Not available for the selected location.') : null;

        // Coupon preview (re-validated with a lock when the order is placed).
        $discount = '0.00';
        $couponMessage = null;
        $code = trim((string) $req->input('coupon_code', ''));
        if ($code !== '') {
            try {
                $phone = \Waimart\Core\Validator::normalizePhone((string) $req->input('customer_phone', '')) ?? '';
                $discount = (new CouponService())->evaluate($code, $cart['subtotal'], Auth::id(), $phone)['discount'];
                $couponMessage = 'Coupon applied: −' . Money::format($discount);
            } catch (ValidationException $e) {
                $couponMessage = $e->errors['coupon_code'] ?? 'This coupon code is not valid.';
            }
        }
        return Response::json($out + [
            'fee_display' => $out['fee'] !== null ? (Money::isZero($fee) ? 'Free' : Money::format($fee)) : '—',
            'discount_display' => Money::isZero($discount) ? null : '−' . Money::format($discount),
            'coupon_message' => $couponMessage,
            'coupon_ok' => !Money::isZero($discount),
            'total_display' => Money::format(Money::sub(Money::add($cart['subtotal'], $fee), $discount)),
        ]);
    }

    public function towns(Request $req): Response
    {
        $county = (string) ($req->query['county'] ?? '');
        return Response::json(['towns' => ctype_digit($county) ? (new DeliveryService())->towns((int) $county) : []]);
    }

    /**
     * Places the order (C2). The one-time checkout token doubles as the idempotency key:
     * a double-click, refresh or network retry returns the same order instead of a second one.
     */
    public function submit(Request $req): Response
    {
        $posted = (string) ($req->post['checkout_token'] ?? '');
        $current = (string) Session::get('checkout_token', '');
        $orders = new OrderService();

        if ($current === '' || !hash_equals($current, $posted)) {
            // A resubmission of a form whose order was already placed.
            $existing = $posted !== '' ? $orders->findByKey($posted) : null;
            if ($existing !== null) {
                if (OrderAccess::canView($existing)) {
                    return Response::redirect('/order/' . $existing['order_number'] . '/confirmation');
                }
                Session::flash('info', 'This order has already been placed.');
                return Response::redirect('/');
            }
            Session::flash('warning', 'Your checkout page was out of date. Please check your details and place your order again.');
            return Response::redirect('/checkout');
        }

        try {
            $result = $orders->place(CartService::current(), $req->post, Auth::user(), $posted, $req->ip());
        } catch (ValidationException $e) {
            if (isset($e->errors['_cart']) || isset($e->errors['_stock'])) {
                Session::flash('warning', $e->errors['_cart'] ?? $e->errors['_stock']);
                return Response::redirect('/cart');
            }
            throw $e;
        }

        $order = $result['order'];
        \Waimart\Services\Marketing\Signals::orderPlaced($order);
        \Waimart\Services\StaffAlerts::orderPlaced($order);          // email + SMS to the staff who must confirm it
        OrderAccess::grant((string) $order['order_number'], 'placed');
        if ($result['access_token'] !== null) {
            // Shown once on the confirmation page so guests can bookmark their private tracking link.
            Session::flash('order_link_token', $result['access_token']);
        }
        Session::set('checkout_token', Crypto::token(32));     // next checkout gets a fresh key
        return Response::redirect('/order/' . $order['order_number'] . '/confirmation');
    }
}
