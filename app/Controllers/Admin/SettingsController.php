<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\Brand;
use Waimart\Services\NotificationService;
use Waimart\Services\SecretStore;
use Waimart\Services\SellerDocumentPolicy;
use Waimart\Services\SettingsService;

/**
 * Admin → Settings: one page per group (SettingsService::GROUPS), plus export/import. All need settings.manage; the
 * SMTP password also needs secrets.manage. Payment and delivery groups redirect to their module pages.
 */
final class SettingsController extends AdminController
{
    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings/general');
    }

    public function group(Request $req): Response
    {
        $group = $this->groupParam($req);
        if (isset(SettingsService::PAGES[$group])) {
            return Response::redirect(SettingsService::PAGES[$group]);   // edited on its module page
        }
        return $this->view('settings/group', [
            'title' => SettingsService::GROUPS[$group][0] . ' — Settings', 'group' => $group,
            'values' => SettingsService::values($group),
            'docModes' => $group === 'marketplace' ? SellerDocumentPolicy::modes() : [],
            'secret' => $group === 'email' ? (new SecretStore())->status('smtp_password') : null,
            'mail' => $group === 'email' ? NotificationService::mailConfig() : null,
        ]);
    }

    /** The SMTP password (secrets.manage): stored encrypted, never shown again. */
    public function smtpPassword(Request $req): Response
    {
        $store = new SecretStore();
        if (!empty($req->post['remove'])) {
            $store->clear('smtp_password', $this->actor());
            return $this->success('The saved SMTP password was removed.', '/admin/settings/email');
        }
        $store->set('smtp_password', (string) ($req->post['secret_smtp_password'] ?? ''), $this->actor());
        return $this->success('SMTP password saved (encrypted).', '/admin/settings/email');
    }

    /** Sends a test email to the signed-in staff member with the saved settings. */
    public function testEmail(Request $req): Response
    {
        $to = (string) (Auth::user()['email'] ?? '');
        $id = (new NotificationService())->email($to, 'Test email from ' . brand_name(),
            "This is a test email from the " . brand_name() . " admin (Settings → Email & SMS).\n\nIf you can read this, email sending works.\n\n" . brand_name(),
            'mail_test', ['user_id' => $this->actor()]);
        $row = \Waimart\Core\App::db()->one('SELECT status, last_error FROM notifications WHERE id = ?', [$id]);
        \Waimart\Services\AuditLogger::log('settings.test_email', 'notification', $id, null, ['status' => $row['status'] ?? null], $this->actor());
        return match ($row['status'] ?? '') {
            'sent' => $this->success("Test email sent to $to. Check the inbox (and the spam folder).", '/admin/settings/email'),
            'logged' => $this->success('Email is not being sent: it was written to the mail log (storage/logs) instead. Choose "Send through a mail server" to send real email.', '/admin/settings/email'),
            default => $this->failure('The test email could not be sent: ' . self::safeError((string) ($row['last_error'] ?? 'unknown error')) . ' Check the mail server, port, encryption, username and password.'),
        };
    }

    private function failure(string $message): Response
    {
        Session::flash('error', $message);
        return Response::redirect('/admin/settings/email');
    }

    /** Mail-server errors can quote the conversation; keep the first line and never anything that looks like a credential. */
    private static function safeError(string $e): string
    {
        $line = trim(strtok($e, "\n") ?: $e);
        return mb_substr((string) preg_replace('/(pass(word)?|auth\S*)\s*[:=]\s*\S+/i', '$1: [hidden]', $line), 0, 200);
    }

    public function saveGroup(Request $req): Response
    {
        $group = $this->groupParam($req);
        SettingsService::guardCredentialRouting($req->post, \Waimart\Core\Auth::can('secrets.manage'));
        (new SettingsService())->save($req->post, $this->actor(), $group);
        return $this->success(SettingsService::GROUPS[$group][0] . ' settings saved.', '/admin/settings/' . $group);
    }

    /** The full form (every registered setting at once), kept for scripts and older clients. */
    public function update(Request $req): Response
    {
        SettingsService::guardCredentialRouting($req->post, \Waimart\Core\Auth::can('secrets.manage'));
        (new SettingsService())->save($req->post, $this->actor());
        return $this->success('Settings saved.', '/admin/settings');
    }

    public function transfer(Request $req): Response
    {
        return $this->view('settings/transfer', ['title' => 'Export & import — Settings', 'group' => 'transfer']);
    }

    public function export(Request $req): Response
    {
        $data = (new SettingsService())->export($this->actor());
        return Response::json($data)
            ->withHeader('Content-Disposition', 'attachment; filename="' . Brand::slug() . '-settings-' . date('Y-m-d') . '.json"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function import(Request $req): Response
    {
        $f = $req->files['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])
            || (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $f['tmp_name'])) || (int) $f['size'] > 256 * 1024) {
            throw new \Waimart\Core\ValidationException(['file' => 'Choose a settings export file (.json, up to 256 KB).']);
        }
        $result = (new SettingsService())->import((string) file_get_contents((string) $f['tmp_name']), $this->actor());
        $msg = "Settings imported: {$result['changed']} changed.";
        if ($result['ignored']) {
            $msg .= ' Not imported (payment numbers, or unknown in this version): ' . implode(', ', array_slice($result['ignored'], 0, 12)) . '.';
        }
        Session::flash('success', $msg);
        return Response::redirect('/admin/settings/transfer');
    }

    private function groupParam(Request $req): string
    {
        $g = (string) $req->param('group');
        return isset(SettingsService::GROUPS[$g]) ? $g : throw HttpException::notFound();
    }
}
