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
        \Waimart\Services\Marketing\Signals::signedIn((int) $user['id']);
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
        \Waimart\Services\Marketing\Signals::registered((int) $user['id'], $req->post, $req->ip());
        return $this->afterSignIn((int) $user['id'], $guestToken, 'Your account has been created. Welcome to ' . brand_name() . '!');
    }

    /** Forgot password, for customers, sellers and staff (?staff=1 from the staff sign-in page). */
    public function forgotForm(Request $req): Response
    {
        if (isset($req->query['staff'])) {
            Session::set('reset_staff', !empty($req->query['staff']));
        }
        return $this->view('auth/forgot', ['title' => 'Forgot password', 'smsAvailable' => self::smsAvailable(), 'staff' => (bool) Session::get('reset_staff', false)]);
    }

    /** Email link or SMS code. The response never says whether the account exists. */
    public function forgot(Request $req): Response
    {
        $login = trim((string) ($req->input('login', '') ?: $req->input('email', '')));
        $channel = self::smsAvailable() ? (string) $req->input('channel', 'email') : 'email';
        $userId = (new AccountService())->requestReset($login, $channel, $req->ip());
        if ($channel === 'sms') {
            Session::set('reset_code_user', $userId);      // server-side only; the page shows the same thing either way
            Session::set('reset_code_for', $login);
            Session::flash('success', 'If an account with those details has a phone number, we have sent a 6-digit code to it by SMS. It expires in '
                . AccountService::RESET_CODE_MINUTES . ' minutes.');
            return Response::redirect('/forgot-password/code');
        }
        Session::flash('success', 'If an account exists with those details, we have sent a link to reset your password to its email address. It expires in '
            . AccountService::RESET_TTL_MINUTES . ' minutes. Check your spam folder too.');
        return Response::redirect(Session::get('reset_staff') ? '/admin/login' : '/login');
    }

    public function codeForm(Request $req): Response
    {
        if (!Session::get('reset_code_for')) {
            return Response::redirect('/forgot-password');
        }
        return $this->view('auth/reset-code', ['title' => 'Enter your reset code', 'for' => (string) Session::get('reset_code_for'),
                                               'minutes' => AccountService::RESET_CODE_MINUTES]);
    }

    /** The right SMS code opens the "set a new password" page. */
    public function code(Request $req): Response
    {
        if (!Session::get('reset_code_for')) {
            return Response::redirect('/forgot-password');
        }
        $uid = Session::get('reset_code_user');
        $token = (new AccountService())->verifyResetCode(is_int($uid) ? $uid : null, (string) $req->input('code', ''), $req->ip());
        Session::forget('reset_code_user');
        Session::forget('reset_code_for');
        return Response::redirect('/reset-password?token=' . rawurlencode($token))->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** SMS reset is offered when SMS is actually delivered (or, in development, written to the mail log). */
    private static function smsAvailable(): bool
    {
        return \Waimart\Services\SmsGateway::enabled() || \Waimart\Services\NotificationService::mailConfig()['driver'] === 'log';
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
            $userId = (new AccountService())->resetPassword($token, $req->post);
        } catch (\Waimart\Core\ValidationException $e) {
            // The reset page sends no Referer (it carries the token), so redirect back explicitly.
            Session::flash('_errors', $e->errors);
            Session::flash('error', $e->errors['_form'] ?? $e->getMessage());
            return Response::redirect('/reset-password?token=' . rawurlencode($token));
        }
        Session::flash('success', 'Your password has been changed. Please sign in with your new password.');
        $staff = (int) \Waimart\Core\App::db()->value('SELECT is_staff FROM users WHERE id = ?', [$userId]) === 1;
        Session::forget('reset_staff');
        return Response::redirect($staff ? '/admin/login' : '/login');
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
