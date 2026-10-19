<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\AccountService;
use Waimart\Services\AuthService;
use Waimart\Services\CartService;

/** Customer sign-in, registration and password reset. */
final class AuthController extends Controller
{
    public function loginForm(Request $req): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in']);
    }

    public function login(Request $req): Response
    {
        $guestToken = CartService::current()->guestToken();
        $user = (new AuthService())->attempt((string) ($req->input('login', '') ?: $req->input('email', '')),   // email or phone; 'email' kept for older forms
            (string) ($req->post['password'] ?? ''), $req->ip());
        if (!empty($user['mfa_required'])) {
            return Response::redirect('/admin/login/two-step');   // two-step sign-in is on for this (staff) account
        }
        return $this->afterSignIn((int) $user['id'], $guestToken, 'Welcome back, ' . $user['first_name'] . '!');
    }

    public function registerForm(Request $req): Response
    {
        return $this->view('auth/register', ['title' => 'Create account', 'open' => \Waimart\Services\AccountService::registrationOpen()]);
    }

    public function register(Request $req): Response
    {
        $guestToken = CartService::current()->guestToken();
        $user = (new AccountService())->register($req->post, $req->ip());
        Auth::login($user);
        return $this->afterSignIn((int) $user['id'], $guestToken, 'Your account has been created. Welcome to ' . brand_name() . '!');
    }

    public function forgotForm(Request $req): Response
    {
        return $this->view('auth/forgot', ['title' => 'Forgot password']);
    }

    public function forgot(Request $req): Response
    {
        (new AccountService())->requestPasswordReset((string) $req->input('email', ''), $req->ip());
        // Identical response whether or not the account exists.
        Session::flash('success', 'If an account exists for that email, we have sent a link to reset your password. It expires in '
            . AccountService::RESET_TTL_MINUTES . ' minutes.');
        return Response::redirect('/login');
    }

    public function resetForm(Request $req): Response
    {
        $token = (string) ($req->query['token'] ?? '');
        $valid = (new AccountService())->resetTokenValid($token);
        return $this->view('auth/reset', ['title' => 'Reset password', 'token' => $token, 'valid' => $valid])
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $req): Response
    {
        $token = (string) $req->input('token', '');
        try {
            (new AccountService())->resetPassword($token, $req->post);
        } catch (\Waimart\Core\ValidationException $e) {
            // The reset page sends no Referer (it carries the token), so redirect back explicitly.
            Session::flash('_errors', $e->errors);
            Session::flash('error', $e->errors['_form'] ?? $e->getMessage());
            return Response::redirect('/reset-password?token=' . rawurlencode($token));
        }
        Session::flash('success', 'Your password has been changed. Please sign in with your new password.');
        return Response::redirect('/login');
    }

    /** Merges the guest cart, clears the guest cookie and returns to the intended page. */
    private function afterSignIn(int $userId, ?string $guestToken, string $welcome): Response
    {
        $messages = CartService::mergeGuestIntoUser($userId, $guestToken);
        CartService::setCookie(null);
        Session::flash('success', $welcome . ($messages ? ' ' . implode(' ', $messages) : ''));
        $intended = (string) Session::get('intended', '/account');
        Session::forget('intended');
        // Only internal storefront paths; never external URLs or the staff area.
        $safe = preg_match('#^/(?!/)[^\s]*$#', $intended) && !str_starts_with($intended, '/admin');
        return Response::redirect($safe ? $intended : '/account');
    }
}
