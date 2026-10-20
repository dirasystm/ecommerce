<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\RateLimiter;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;
use Waimart\Repositories\OrderRepository;
use Waimart\Services\OrderAccess;
use Waimart\Services\OrderClaimService;
use Waimart\Services\OrderNotifier;
use Waimart\Services\OrderWorkflow;

/**
 * Order confirmation and tracking. Access requires ownership or proof (private token or
 * order number + phone); unknown orders and denied access look identical (404).
 */
final class OrderController extends Controller
{
    public function confirmation(Request $req): Response
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        return $this->detailView($order, 'orders/confirmation', 'Order placed', true);
    }

    /** Private link from emails/SMS: /track/WM-XXXX?t=token → grants the session, then drops the token from the URL. */
    public function track(Request $req): Response
    {
        $number = (string) $req->param('number');
        $token = (string) ($req->query['t'] ?? '');
        if ($token !== '') {
            (new RateLimiter(App::db()))->enforce('track-token|' . $req->ip(), 30, 900);
            $order = (new OrderRepository(App::db()))->byNumber($number);
            if ($order !== null && OrderAccess::tokenValid((int) $order['id'], $token)) {
                OrderAccess::grant($number, 'token');
                return Response::redirect('/track/' . $number)->withHeader('Referrer-Policy', 'no-referrer');
            }
            throw HttpException::notFound('We could not find that order.');
        }
        $order = $this->visibleOrder($number);
        return $this->detailView($order, 'orders/track', 'Track order ' . $number, OrderAccess::isOwner($order));
    }

    public function lookupForm(Request $req): Response
    {
        return $this->view('orders/lookup', ['title' => 'Track your order']);
    }

    /**
     * Order number (or the delivery tracking number given at dispatch) + phone lookup.
     * Rate-limited; one generic error for every failure.
     */
    public function lookup(Request $req): Response
    {
        $number = strtoupper(trim(mb_substr((string) $req->input('order_number', ''), 0, 80)));
        $phone = Validator::normalizePhone((string) $req->input('phone', ''));
        $rl = new RateLimiter(App::db());
        if (!$rl->attempt('track-ip|' . $req->ip(), 10, 900) || !$rl->attempt('track-order|' . $number, 10, 3600)) {
            throw ValidationException::withMessage('Too many attempts. Please wait 15 minutes and try again.');
        }
        $repo = new OrderRepository(App::db());
        $order = $repo->byNumber($number) ?? ($phone !== null ? $repo->byTrackingNumber($number, $phone) : null);
        if ($order === null || $phone === null || !hash_equals((string) $order['customer_phone'], $phone)) {
            throw ValidationException::withMessage('We could not find an order matching those details. Check the order or tracking number and the phone number used at checkout.');
        }
        $number = (string) $order['order_number'];
        OrderAccess::grant($number, 'lookup');
        return Response::redirect('/track/' . $number);
    }

    public function accountIndex(Request $req): Response
    {
        return $this->view('account/orders', [
            'title' => 'My orders', 'section' => 'orders',
            'orders' => (new OrderRepository(App::db()))->forUser((int) Auth::id()),
        ]);
    }

    public function accountShow(Request $req): Response
    {
        $order = (new OrderRepository(App::db()))->byNumber((string) $req->param('number'));
        if ($order === null || !OrderAccess::isOwner($order)) {
            throw HttpException::notFound('Order not found.');
        }
        return $this->detailView($order, 'orders/track', 'Order ' . $order['order_number'], true);
    }

    /**
     * Customer cancellation (D2): allowed for the owner or a verified guest, and only before the
     * order is paid/confirmed. Stock is released exactly once by the workflow.
     */
    public function cancel(Request $req): Response
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        if (!OrderWorkflow::customerCanCancel($order)) {
            throw ValidationException::withMessage('This order can no longer be cancelled online. Please contact customer care.');
        }
        $reason = (string) $req->input('reason', '');
        $reasons = ['changed_mind' => 'Changed my mind', 'ordered_by_mistake' => 'Ordered by mistake', 'found_cheaper' => 'Found it cheaper elsewhere',
                    'delivery_too_long' => 'Delivery takes too long', 'other' => 'Other'];
        if (!isset($reasons[$reason])) {
            throw new ValidationException(['reason' => 'Please choose a reason.']);
        }
        $text = $reasons[$reason];
        if ($reason === 'other') {
            // "Other" needs the customer's own words (one line, no control characters), stored with the order.
            $other = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $req->input('reason_other', '')));
            if (mb_strlen($other) < 3) {
                throw new ValidationException(['reason_other' => 'Please tell us why you are cancelling.']);
            }
            if (mb_strlen($other) > 200) {
                throw new ValidationException(['reason_other' => 'Please keep the reason under 200 characters.']);
            }
            $text = 'Other: ' . $other;
        }
        $updated = (new OrderWorkflow())->transition(
            (int) $order['id'], 'cancelled', ['type' => 'customer', 'id' => Auth::id()], 'Cancelled by customer', ['reason' => $text]
        );
        OrderNotifier::statusChanged($updated);
        Session::flash('success', 'Your order has been cancelled.');
        return Response::redirect(OrderAccess::isOwner($order) ? '/account/orders/' . $order['order_number'] : '/track/' . $order['order_number']);
    }

    /** Customer submits an M-Pesa transaction code (marks the payment "awaiting verification" only). */
    public function submitPayment(Request $req): Response
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        (new \Waimart\Services\PaymentService())->submit((int) $order['id'], $req->post, $req->ip(), Auth::id());
        Session::flash('success', 'Thank you — we have received your M-Pesa code and will verify it shortly. Your order is confirmed only after verification.');
        return Response::redirect(OrderAccess::isOwner($order) ? '/account/orders/' . $order['order_number'] : '/track/' . $order['order_number']);
    }

    public function returnForm(Request $req): Response
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        $items = (new \Waimart\Services\ReturnService())->returnableItems($order);
        if (!$items) {
            throw ValidationException::withMessage('This order is not eligible for an online return. Please contact customer care.');
        }
        return $this->view('orders/return', ['title' => 'Return items', 'order' => $order, 'items' => $items,
            'reasons' => \Waimart\Services\ReturnService::REASONS, 'windowDays' => \Waimart\Core\Settings::int('return_window_days', 7)]);
    }

    public function requestReturn(Request $req): Response
    {
        $order = $this->visibleOrder((string) $req->param('number'));
        $rets = (new \Waimart\Services\ReturnService())->request((int) $order['id'], (array) ($req->post['qty'] ?? []),
            (string) $req->input('reason', ''), (string) $req->input('details', ''), Auth::id());
        Session::flash('success', count($rets) === 1
            ? "Return request {$rets[0]['return_number']} received. We will review it and contact you."
            : 'Return requests ' . implode(', ', array_column($rets, 'return_number')) . ' received (one per seller). We will review them and contact you.');
        return Response::redirect(OrderAccess::isOwner($order) ? '/account/orders/' . $order['order_number'] : '/track/' . $order['order_number']);
    }

    // ------------------------------------------------------------------ guest-to-account linking (D3)

    public function linkForm(Request $req): Response
    {
        return $this->view('orders/link', ['title' => 'Add a past order', 'section' => 'orders', 'step' => Session::get('claim_pending') ? 'code' : 'number']);
    }

    public function linkStart(Request $req): Response
    {
        $claimId = (new OrderClaimService())->start((int) Auth::id(), (string) $req->input('order_number', ''), $req->ip());
        // Same next step whether or not anything was sent (no order enumeration).
        Session::set('claim_pending', ['id' => $claimId, 'at' => time()]);
        Session::flash('info', 'If that order can be added to your account, we have sent a 6-digit code to the phone number (and email, if given) used for the order.');
        return Response::redirect('/account/orders/link');
    }

    public function linkVerify(Request $req): Response
    {
        $pending = (array) Session::get('claim_pending', []);
        $number = (new OrderClaimService())->verify((int) Auth::id(), isset($pending['id']) ? (int) $pending['id'] : null, (string) $req->input('code', ''));
        Session::forget('claim_pending');
        Session::flash('success', "Order $number has been added to your account.");
        return Response::redirect('/account/orders/' . $number);
    }

    public function linkCancel(Request $req): Response
    {
        Session::forget('claim_pending');
        return Response::redirect('/account/orders/link');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function visibleOrder(string $number): array
    {
        $order = (new OrderRepository(App::db()))->byNumber($number);
        if ($order === null || !OrderAccess::canView($order)) {
            throw HttpException::notFound('We could not find that order.');
        }
        return $order;
    }

    private function detailView(array $order, string $template, string $title, bool $full): Response
    {
        return $this->view($template, [
            'title' => $title,
            'order' => (new OrderRepository(App::db()))->details($order),
            'full' => $full,              // owners see full personal details; token/lookup holders see masked details
            'collectionCode' => OrderAccess::canSeeCollectionCode($order) ? \Waimart\Services\CollectionService::codeFor($order) : null,
            'linkToken' => Session::getFlash('order_link_token'),
        ])->withHeader('Cache-Control', 'no-store, private')
          ->withHeader('Referrer-Policy', 'no-referrer')
          ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
