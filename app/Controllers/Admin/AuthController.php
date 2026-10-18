<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\View;
use Waimart\Services\AuditLogger;
use Waimart\Services\AuthService;

final class AuthController
{
    public function form(Request $req): Response
    {
        if (Auth::isStaff()) {
            return Response::redirect('/admin');
        }
        return Response::html(View::render('admin/login', ['title' => 'Staff sign in'], 'layouts/minimal'))
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function login(Request $req): Response
    {
        (new AuthService())->attempt((string) $req->input('email', ''), (string) ($req->post['password'] ?? ''), $req->ip(), true);
        $intended = (string) Session::get('intended', '/admin');
        Session::forget('intended');
        // Only allow internal admin paths as post-login destinations.
        return Response::redirect(str_starts_with($intended, '/admin') ? $intended : '/admin');
    }

    public function logout(Request $req): Response
    {
        $wasStaff = Auth::isStaff();
        $id = Auth::id();
        if ($wasStaff && $id) {
            AuditLogger::log('auth.staff_logout', 'user', $id, null, null, $id);
        }
        Auth::logout();
        Session::flash('success', 'You have been signed out.');
        return Response::redirect($wasStaff ? '/admin/login' : '/');
    }
}
