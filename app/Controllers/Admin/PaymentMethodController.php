<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\PaymentOptions;
use Waimart\Services\SettingsService;

/**
 * Admin → Finance → Payment methods (payments.configure): which methods are offered at checkout, how they are
 * presented, their limits and (M-Pesa) where customers pay. Payment records themselves are under M-Pesa payments.
 */
final class PaymentMethodController extends AdminController
{
    public function index(Request $req): Response
    {
        return $this->view('payment-methods/index', [
            'title' => 'Payment methods', 'methods' => PaymentOptions::all(), 'health' => PaymentOptions::health(),
            'maxSubmissions' => SettingsService::values('payments')['max_payment_submissions'],
        ]);
    }

    public function edit(Request $req): Response
    {
        $code = $this->code($req);
        return $this->view('payment-methods/edit', [
            'title' => PaymentOptions::label($code) . ' — Payment methods', 'code' => $code, 'm' => PaymentOptions::all()[$code],
            'account' => array_intersect_key(SettingsService::values('payments'), array_flip(PaymentOptions::ACCOUNT_SETTINGS[$code])),
        ]);
    }

    public function update(Request $req): Response
    {
        $code = $this->code($req);
        (new PaymentOptions())->save($code, $req->post, $this->actor());
        return $this->success(PaymentOptions::label($code) . ' saved.', '/admin/payment-methods');
    }

    /** Rules that apply to every M-Pesa method. */
    public function saveRules(Request $req): Response
    {
        (new SettingsService())->saveKeys($req->post, $this->actor(), ['max_payment_submissions']);
        return $this->success('Payment rules saved.', '/admin/payment-methods');
    }

    private function code(Request $req): string
    {
        $c = (string) $req->param('code');
        return isset(PaymentOptions::all()[$c], PaymentOptions::LABELS[$c]) ? $c : throw HttpException::notFound();
    }
}
