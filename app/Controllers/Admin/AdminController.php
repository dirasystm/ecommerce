<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Auth;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\View;

/**
 * Base for staff controllers. Routes are already guarded by `staff` + `can:` middleware;
 * services re-check sensitive permissions themselves where it matters.
 */
abstract class AdminController
{
    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render('admin/' . $template, $data, 'layouts/admin'), $status)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    protected function actor(): int
    {
        return (int) Auth::id();
    }

    protected function success(string $message, string $to): Response
    {
        Session::flash('success', $message);
        return Response::redirect($to);
    }

    protected function intParam(\Waimart\Core\Request $req, string $key): int
    {
        $v = (string) $req->param($key);
        return ctype_digit($v) ? (int) $v : 0;
    }
}
