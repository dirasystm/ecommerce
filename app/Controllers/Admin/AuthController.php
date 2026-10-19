<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\View;
use Waimart\Services\AuditLogger;
use Waimart\Services\AuthEvents;
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
        $user = (new AuthService())->attempt((string) ($req->input('login', '') ?: $req->input('email', '')),   // email or phone
            (string) ($req->post['password'] ?? ''), $req->ip(), true);
        if (!empty($user['mfa_required'])) {
            return Response::redirect('/admin/login/two-step');
        }
        return $this->toIntended();
    }

    /** Second step of signing in (two-step sign-in is on for this account). */
    public function verifyForm(Request $req): Response
    {
        if (AuthService::pendingMfaUser() === null) {
            Session::flash('error', 'Please sign in again.');
            return Response::redirect('/admin/login');
        }
        return Response::html(View::render('admin/login-verify', ['title' => 'Two-step sign-in'], 'layouts/minimal'))
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')->withHeader('Cache-Control', 'no-store');
    }

    public function verify(Request $req): Response
    {
        $user = (new AuthService())->completeMfa((string) ($req->post['code'] ?? ''), $req->ip());
        if ((int) $user['is_staff'] !== 1) {
            return Response::redirect('/account');
        }
        return $this->toIntended();
    }

    public function logout(Request $req): Response
    {
        $wasStaff = Auth::isStaff();
        $id = Auth::id();
        if ($wasStaff && $id) {
            AuditLogger::log('auth.staff_logout', 'user', $id, null, null, $id);
        }
        if ($id) {
            AuthEvents::record('logout', $id, null, $wasStaff);
        }
        Auth::logout();
        Session::flash('success', 'You have been signed out.');
        return Response::redirect($wasStaff ? '/admin/login' : '/');
    }

    private function toIntended(): Response
    {
        $intended = (string) Session::get('intended', '/admin');
        Session::forget('intended');
        // Only allow internal admin paths as post-login destinations.
        return Response::redirect(str_starts_with($intended, '/admin') && !str_starts_with($intended, '/admin/login') ? $intended : '/admin');
    }
}
