<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\AccountService;
use Waimart\Services\AuditLogger;
use Waimart\Services\AuthEvents;
use Waimart\Services\MfaService;

/**
 * Admin → My account (every staff member, for themselves): password, two-step sign-in, sessions and recent sign-ins.
 * Open even while a password change or two-step setup is required (see the staff middleware).
 */
final class AccountController extends AdminController
{
    public function show(Request $req): Response
    {
        $user = (array) Auth::user();
        $setupSecret = Session::get('mfa_setup_secret');
        return $this->view('account/index', [
            'title' => 'My account', 'user' => $user, 'mfaOn' => MfaService::enabled($user), 'required' => MfaService::requiredForStaff(),
            'setupSecret' => is_string($setupSecret) ? $setupSecret : null,
            'setupUri' => is_string($setupSecret) ? MfaService::otpauthUri($setupSecret, (string) $user['email']) : null,
            'recoveryLeft' => (new MfaService())->unusedRecoveryCodes((int) $user['id']),
            'newCodes' => Session::getFlash('mfa_recovery_codes'),
            'signIns' => AuthEvents::recent((int) $user['id'], false, 10),
            'roles' => array_column(App::db()->all('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name', [$user['id']]), 'name'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function password(Request $req): Response
    {
        (new AccountService())->changePassword($this->actor(), $req->post);
        AuditLogger::log('auth.password_changed', 'user', $this->actor(), null, null, $this->actor());
        return $this->success('Password changed. Your other sessions have been signed out.', '/admin/account');
    }

    /** Step 1 of turning on two-step sign-in: a new secret, kept in this session until the app proves it works. */
    public function mfaStart(Request $req): Response
    {
        Session::set('mfa_setup_secret', MfaService::newSecret());
        return Response::redirect('/admin/account#two-step');
    }

    public function mfaEnable(Request $req): Response
    {
        $secret = (string) Session::get('mfa_setup_secret', '');
        $codes = (new MfaService())->enable($this->actor(), $secret, (string) ($req->post['code'] ?? ''), (string) ($req->post['password'] ?? ''));
        Session::forget('mfa_setup_secret');
        Session::flash('mfa_recovery_codes', $codes);
        return $this->success('Two-step sign-in is on. Save your recovery codes now: they are shown only once.', '/admin/account#two-step');
    }

    public function mfaCancel(Request $req): Response
    {
        Session::forget('mfa_setup_secret');
        return Response::redirect('/admin/account#two-step');
    }

    public function mfaDisable(Request $req): Response
    {
        (new MfaService())->disable($this->actor(), (string) ($req->post['password'] ?? ''));
        return $this->success('Two-step sign-in is off.', '/admin/account#two-step');
    }

    public function recoveryCodes(Request $req): Response
    {
        $codes = (new MfaService())->regenerateRecoveryCodes($this->actor(), (string) ($req->post['password'] ?? ''));
        Session::flash('mfa_recovery_codes', $codes);
        return $this->success('New recovery codes: the old ones no longer work. Save these now.', '/admin/account#two-step');
    }

    /** Signs out every other session of this account; this one stays signed in. */
    public function signOutOthers(Request $req): Response
    {
        App::db()->exec('UPDATE users SET sessions_revoked_at = NOW() WHERE id = ?', [$this->actor()]);
        Auth::touchAuthTime();
        AuditLogger::log('auth.sessions_revoked', 'user', $this->actor(), null, null, $this->actor());
        AuthEvents::record('sessions_revoked', $this->actor());
        return $this->success('Your other sessions have been signed out.', '/admin/account');
    }
}
