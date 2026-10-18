<?php

declare(strict_types=1);

namespace Waimart\Controllers\Seller;

use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\View;
use Waimart\Services\SellerContext;

/**
 * Base for Seller Centre controllers. Routes carry the `seller` middleware, so a seller context always exists;
 * the seller id used by every query comes from SellerContext (server-side membership), never from the request.
 */
abstract class SellerController
{
    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render('seller/' . $template, $data + ['seller' => $this->seller()], 'layouts/seller'), $status)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @return array<string,mixed> */
    protected function seller(): array
    {
        return SellerContext::current() ?? throw \Waimart\Core\HttpException::forbidden();
    }

    protected function sellerId(): int
    {
        return (int) $this->seller()['id'];
    }

    protected function success(string $message, string $to): Response
    {
        Session::flash('success', $message);
        return Response::redirect($to);
    }
}
