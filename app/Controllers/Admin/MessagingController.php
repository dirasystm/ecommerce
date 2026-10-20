<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;
use Waimart\Services\AuditLogger;
use Waimart\Services\NotificationService;
use Waimart\Services\SecretStore;
use Waimart\Services\SettingsService;
use Waimart\Services\SmsGateway;

/**
 * Admin → Email & SMS (messaging.manage): the SMS gateway (Talksasa) and its balance, sent / failed counts for SMS and
 * email, test messages, and the message log with search, re-sending of failed messages and delivery reports.
 */
final class MessagingController extends AdminController
{
    private const PER_PAGE = 25;

    /** Templates whose stored body is redacted (it held a code or link): never re-sent from the log. */
    private const NO_RESEND = ['password_reset', 'password_reset_code', 'order_claim_code', 'order_ready_for_collection', 'order_placed'];

    /** How long a fetched SMS balance is shown before asking the gateway again. */
    private const BALANCE_TTL = 120;

    public function index(Request $req): Response
    {
        $db = App::db();
        $stats = ['sms' => [], 'email' => []];
        foreach ($db->all(
            "SELECT channel, status, COUNT(*) AS total, SUM(created_at >= CURDATE()) AS today, SUM(created_at >= NOW() - INTERVAL 30 DAY) AS month
               FROM notifications GROUP BY channel, status"
        ) as $r) {
            $stats[$r['channel']][$r['status']] = ['total' => (int) $r['total'], 'today' => (int) $r['today'], 'month' => (int) $r['month']];
        }
        $delivered = (int) $db->value("SELECT COUNT(*) FROM notifications WHERE channel = 'sms' AND status = 'sent' AND provider_status LIKE 'deliver%'");

        // The message log.
        $where = ['1=1'];
        $params = [];
        $channel = (string) ($req->query['channel'] ?? '');
        if (in_array($channel, ['sms', 'email'], true)) {
            $where[] = 'n.channel = ?';
            $params[] = $channel;
        }
        $status = (string) ($req->query['status'] ?? '');
        if (in_array($status, ['queued', 'sent', 'failed', 'logged'], true)) {
            $where[] = 'n.status = ?';
            $params[] = $status;
        }
        $search = trim(mb_substr((string) ($req->query['q'] ?? ''), 0, 100));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            // Phones are stored as 2547…; staff type them as 07… or +254 7…, often with spaces.
            $digits = preg_replace('/[\s()+-]+/', '', $search) ?? '';
            $phone = ctype_digit($digits) && strlen($digits) >= 3 ? (str_starts_with($digits, '0') ? '254' . substr($digits, 1) : $digits) : null;
            $where[] = '(n.recipient LIKE ? OR n.subject LIKE ? OR n.body LIKE ? OR n.template LIKE ? OR o.order_number LIKE ? OR n.last_error LIKE ?'
                . ($phone !== null ? ' OR n.recipient LIKE ?' : '') . ')';
            array_push($params, ...array_fill(0, 6, $like));
            if ($phone !== null) {
                $params[] = $phone . '%';
            }
        }
        $perPage = in_array((int) ($req->query['per'] ?? 0), [25, 50, 100], true) ? (int) $req->query['per'] : self::PER_PAGE;
        $w = implode(' AND ', $where);
        $from = 'FROM notifications n LEFT JOIN orders o ON o.id = n.order_id';
        $total = (int) $db->value("SELECT COUNT(*) $from WHERE $w", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($req->query['page'] ?? 1)));
        $rows = $db->all("SELECT n.*, o.order_number $from WHERE $w ORDER BY n.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);

        return $this->view('messaging/index', [
            'title' => 'Email & SMS', 'stats' => $stats, 'delivered' => $delivered,
            'sms' => SettingsService::values('sms'), 'token' => (new SecretStore())->status('sms_api_token'),
            'mail' => NotificationService::mailConfig(), 'balance' => $this->balance(!empty($req->query['refresh'])),
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage,
            'channel' => $channel, 'status' => $status, 'search' => $search, 'noResend' => self::NO_RESEND,
        ]);
    }

    /** Gateway settings: on/off, sender ID, endpoint. */
    public function saveSms(Request $req): Response
    {
        (new SettingsService())->save($req->post, $this->actor(), 'sms');
        Session::forget('sms_balance');
        return $this->success('SMS settings saved.', '/admin/messaging');
    }

    /** The API token (also needs secrets.manage): stored encrypted, never shown again. */
    public function token(Request $req): Response
    {
        if (!Auth::can('secrets.manage')) {
            throw HttpException::forbidden();
        }
        $store = new SecretStore();
        Session::forget('sms_balance');
        if (!empty($req->post['remove'])) {
            $store->clear('sms_api_token', $this->actor());
            return $this->success('The saved SMS API token was removed. SMS can no longer be sent until a new token is saved.', '/admin/messaging');
        }
        $store->set('sms_api_token', trim((string) ($req->post['secret_sms_api_token'] ?? '')), $this->actor());
        $b = (new SmsGateway())->balance();
        return $b['ok'] ? $this->success('API token saved (encrypted). Talksasa accepted it: balance ' . $b['balance'] . '.', '/admin/messaging')
            : $this->failure('API token saved (encrypted), but the check failed: ' . $b['error']);
    }

    /** Sends a test SMS through the gateway (even before SMS is switched on, so the setup can be tried first). */
    public function testSms(Request $req): Response
    {
        $phone = Validator::normalizePhone((string) $req->input('phone', ''));
        if ($phone === null) {
            throw new ValidationException(['phone' => 'Enter a Kenyan mobile number, e.g. 0712 345 678.']);
        }
        $text = trim((string) $req->input('message', ''));
        if (mb_strlen($text) > 300) {
            throw new ValidationException(['message' => 'Keep the test message under 300 characters.']);
        }
        $text = $text !== '' ? $text : 'Test SMS from ' . brand_name() . '. If you can read this, SMS sending works.';
        $id = (new NotificationService(new SmsGateway()))->sms($phone, $text, 'sms_test', ['user_id' => $this->actor()]);
        $row = App::db()->one('SELECT status, last_error FROM notifications WHERE id = ?', [$id]);
        AuditLogger::log('messaging.test_sms', 'notification', $id, null, ['status' => $row['status'] ?? null], $this->actor());
        Session::forget('sms_balance');
        if (($row['status'] ?? '') !== 'sent') {
            return $this->failure('The test SMS was not sent: ' . ($row['last_error'] ?? 'unknown error'));
        }
        return $this->success('Test SMS sent to ' . Validator::displayPhone($phone) . '.'
            . (SmsGateway::enabled() ? '' : ' Customer SMS is still switched off: choose "Send through Talksasa" and save to start sending them.'), '/admin/messaging');
    }

    public function testEmail(Request $req): Response
    {
        $to = trim((string) $req->input('email', '')) ?: (string) (Auth::user()['email'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => 'Enter a valid email address.']);
        }
        $id = (new NotificationService())->email($to, 'Test email from ' . brand_name(),
            "This is a test email from the " . brand_name() . " admin (Email & SMS).\n\nIf you can read this, email sending works.\n\n" . brand_name(),
            'mail_test', ['user_id' => $this->actor()]);
        $row = App::db()->one('SELECT status, last_error FROM notifications WHERE id = ?', [$id]);
        AuditLogger::log('messaging.test_email', 'notification', $id, null, ['status' => $row['status'] ?? null], $this->actor());
        return match ($row['status'] ?? '') {
            'sent' => $this->success("Test email sent to $to. Check the inbox (and the spam folder).", '/admin/messaging'),
            'logged' => $this->success('Email is not being sent: it was written to the mail log (storage/logs) instead. Choose "Send through a mail server" under Settings → Email to send real email.', '/admin/messaging'),
            default => $this->failure('The test email could not be sent: ' . self::safeError((string) ($row['last_error'] ?? 'unknown error')) . ' Check the mail server settings under Settings → Email.'),
        };
    }

    /** Re-sends a failed SMS or email as a new log entry (not messages that held a code or private link). */
    public function retry(Request $req): Response
    {
        $n = App::db()->one('SELECT * FROM notifications WHERE id = ?', [$this->intParam($req, 'id')]) ?? throw HttpException::notFound();
        if ($n['status'] !== 'failed' || in_array($n['template'], self::NO_RESEND, true)) {
            throw ValidationException::withMessage('Only failed messages without codes or private links can be re-sent. For those, repeat the original action (e.g. re-issue the code).');
        }
        $ctx = ['order_id' => $n['order_id'] ? (int) $n['order_id'] : null, 'user_id' => $n['user_id'] ? (int) $n['user_id'] : null];
        $svc = new NotificationService();
        $newId = $n['channel'] === 'sms'
            ? $svc->sms((string) $n['recipient'], (string) $n['body'], (string) $n['template'], $ctx)
            : $svc->email((string) $n['recipient'], (string) $n['subject'], (string) $n['body'], (string) $n['template'], $ctx);
        $new = App::db()->one('SELECT status, last_error FROM notifications WHERE id = ?', [$newId]);
        AuditLogger::log('notification.resent', 'notification', (int) $n['id'], null, ['new_id' => $newId, 'status' => $new['status'] ?? null], $this->actor());
        return match ($new['status'] ?? '') {
            'sent' => $this->success('Message re-sent.', '/admin/messaging' . self::back($req)),
            'logged' => $this->success('Recorded in the log again, but not sent: ' . ($n['channel'] === 'sms' ? 'SMS sending is switched off.' : 'email goes to the mail log.'), '/admin/messaging' . self::back($req)),
            default => $this->failure('Still failing: ' . self::safeError((string) ($new['last_error'] ?? 'unknown error')), self::back($req)),
        };
    }

    /** Asks the gateway for delivery reports of recent SMS (last 3 days, up to 50). Reports of failure mark the SMS failed. */
    public function delivery(Request $req): Response
    {
        $db = App::db();
        $rows = $db->all("SELECT id, provider_ref FROM notifications WHERE channel = 'sms' AND status = 'sent' AND provider_ref IS NOT NULL
                            AND sent_at >= NOW() - INTERVAL 3 DAY AND (provider_status IS NULL OR provider_status NOT LIKE 'deliver%')
                          ORDER BY id DESC LIMIT 50");
        if (!$rows) {
            return $this->success('No recent SMS are waiting for a delivery report.', '/admin/messaging');
        }
        $gw = new SmsGateway();
        $updated = $failed = $errors = 0;
        foreach ($rows as $r) {
            $s = $gw->status((string) $r['provider_ref']);
            if (!$s['ok']) {
                $errors++;
                continue;
            }
            if (SmsGateway::reportFailed($s['status'])) {
                $db->exec("UPDATE notifications SET status = 'failed', provider_status = ?, last_error = ? WHERE id = ?", [$s['status'], 'Not delivered: ' . $s['status'], $r['id']]);
                $failed++;
            } else {
                $db->exec('UPDATE notifications SET provider_status = ? WHERE id = ?', [$s['status'], $r['id']]);
            }
            $updated++;
        }
        AuditLogger::log('messaging.delivery_checked', 'notification', null, null, ['checked' => count($rows), 'updated' => $updated, 'failed' => $failed], $this->actor());
        return $this->success("Delivery reports checked for " . count($rows) . " SMS: $updated updated" . ($failed ? ", $failed not delivered" : '') . ($errors ? ", $errors could not be checked" : '') . '.', '/admin/messaging');
    }

    /** @return array{state:string,balance?:?string,expires?:?string,error?:?string,checked_at?:int} */
    private function balance(bool $refresh): array
    {
        $token = (new SecretStore())->status('sms_api_token');
        if (!$token['set']) {
            return ['state' => 'no_token'];
        }
        $cached = Session::get('sms_balance');
        if (!$refresh && is_array($cached) && time() - (int) ($cached['checked_at'] ?? 0) < self::BALANCE_TTL) {
            return $cached;
        }
        $b = (new SmsGateway())->balance();
        $out = ['state' => $b['ok'] ? 'ok' : 'error', 'balance' => $b['balance'], 'expires' => $b['expires'], 'error' => $b['error'], 'checked_at' => time()];
        Session::set('sms_balance', $out);
        return $out;
    }

    private function failure(string $message, string $query = ''): Response
    {
        Session::flash('error', $message);
        return Response::redirect('/admin/messaging' . $query);
    }

    /** Keeps the log's filters and page after an action on one row. */
    private static function back(Request $req): string
    {
        $q = array_filter(array_intersect_key($req->post, array_flip(['channel', 'status', 'q', 'page', 'per'])), fn ($v) => is_string($v) && $v !== '');
        return $q ? '?' . http_build_query($q) : '';
    }

    /** Mail-server errors can quote the conversation; keep the first line and never anything that looks like a credential. */
    private static function safeError(string $e): string
    {
        $line = trim(strtok($e, "\n") ?: $e);
        return mb_substr((string) preg_replace('/(pass(word)?|auth\S*|bearer)\s*[:=]?\s*\S+/i', '$1: [hidden]', $line), 0, 200);
    }
}
