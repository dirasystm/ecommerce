<?php

declare(strict_types=1);

namespace Waimart\Services;

use PHPMailer\PHPMailer\PHPMailer;
use Waimart\Core\App;
use Waimart\Core\Logger;
use Waimart\Core\Settings;

/**
 * Outbound notifications. Every message is recorded in `notifications` (with secrets
 * redacted). Email goes out via SMTP when configured; in development the "log" driver writes
 * the full message to storage/logs/mail-*.log instead. SMS is stored in the outbox only
 * (no SMS provider configured — see docs).
 *
 * How email is sent comes from Settings → Email & SMS when chosen there (password in the encrypted SecretStore), and
 * otherwise from the server configuration (config.local.php). Wording comes from MessageTemplates (sendTemplate).
 */
final class NotificationService
{
    /**
     * Sends a message from its template. Recipients without an address/phone are skipped, a channel switched off in
     * the template is skipped, and customers' preferences are respected unless the template must always be sent.
     * @param array<string,string|int|float|null> $vars
     * @param array{email?:?string,phone?:?string,prefs?:?array<string,mixed>} $to prefs: notify_email/notify_sms (null = guest: send)
     * @param array{user_id?:?int,order_id?:?int,secrets?:list<string>,log_as?:string} $ctx log_as: the name recorded in the message log
     * @return int messages queued
     */
    public function sendTemplate(string $code, array $vars, array $to, array $ctx = []): int
    {
        $msg = (new MessageTemplates())->render($code, $vars);
        $prefs = $to['prefs'] ?? null;
        $log = $ctx['log_as'] ?? $code;
        unset($ctx['log_as']);
        $n = 0;
        if ($msg['email'] !== null && (string) ($to['email'] ?? '') !== '' && ($msg['always'] || $prefs === null || (int) ($prefs['notify_email'] ?? 1) === 1)) {
            $this->email((string) $to['email'], (string) $msg['subject'], $msg['email'], $log, $ctx);
            $n++;
        }
        if ($msg['sms'] !== null && (string) ($to['phone'] ?? '') !== '' && ($msg['always'] || $prefs === null || (int) ($prefs['notify_sms'] ?? 1) === 1)) {
            $this->sms((string) $to['phone'], $msg['sms'], $log, $ctx);
            $n++;
        }
        return $n;
    }

    /**
     * Effective mail transport: Settings → Email & SMS when a method is chosen there, else the server configuration.
     * @return array{driver:string,host:string,port:int,username:string,password:string,encryption:string,from_email:string,from_name:string,reply_to:string,source:string}
     */
    public static function mailConfig(): array
    {
        $s = fn (string $k) => trim((string) Settings::get($k, ''));
        $common = ['from_email' => $s('mail_from_email') ?: (string) config('mail.from_email', ''),
                   'from_name' => $s('mail_from_name') ?: (string) (config('mail.from_name') ?: brand_name()),
                   'reply_to' => $s('mail_reply_to') ?: $s('store_email')];
        if (in_array($s('mail_driver'), ['smtp', 'log'], true)) {
            return ['driver' => $s('mail_driver'), 'host' => $s('smtp_host'), 'port' => (int) ($s('smtp_port') ?: 587), 'username' => $s('smtp_username'),
                    'password' => (string) (new SecretStore())->get('smtp_password'), 'encryption' => $s('smtp_encryption') ?: 'tls', 'source' => 'settings'] + $common;
        }
        $cfg = (array) config('mail.smtp', []);
        return ['driver' => (string) config('mail.driver', 'log'), 'host' => (string) ($cfg['host'] ?? ''), 'port' => (int) ($cfg['port'] ?? 587),
                'username' => (string) ($cfg['username'] ?? ''), 'password' => (string) ($cfg['password'] ?? ''), 'encryption' => (string) ($cfg['encryption'] ?? 'tls'),
                'source' => 'server'] + $common;
    }

    /**
     * @param array{user_id?:?int,order_id?:?int,secrets?:list<string>} $ctx secrets are redacted from the stored copy
     */
    public function email(string $to, string $subject, string $body, string $template, array $ctx = []): int
    {
        $stored = $this->redact($body, $ctx['secrets'] ?? []);
        $id = App::db()->insert('notifications', [
            'user_id' => $ctx['user_id'] ?? null,
            'order_id' => $ctx['order_id'] ?? null,
            'channel' => 'email',
            'template' => $template,
            'recipient' => mb_substr($to, 0, 190),
            'subject' => mb_substr($subject, 0, 190),
            'body' => $stored,
        ]);

        $mail = self::mailConfig();
        try {
            if ($mail['driver'] === 'smtp') {
                $this->sendSmtp($mail, $to, $subject, $body);
                $status = 'sent';
            } else {
                $this->writeLog('email', $to, $subject, $body);
                $status = 'logged';
            }
            App::db()->exec("UPDATE notifications SET status = ?, attempts = attempts + 1, sent_at = NOW() WHERE id = ?", [$status, $id]);
        } catch (\Throwable $e) {
            Logger::error('Email send failed', ['notification_id' => $id, 'error' => $e->getMessage()]);
            App::db()->exec("UPDATE notifications SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $id]);
        }
        return $id;
    }

    /** SMS outbox (no gateway yet). @param array{user_id?:?int,order_id?:?int,secrets?:list<string>} $ctx */
    public function sms(string $phone, string $body, string $template, array $ctx = []): int
    {
        $id = App::db()->insert('notifications', [
            'user_id' => $ctx['user_id'] ?? null,
            'order_id' => $ctx['order_id'] ?? null,
            'channel' => 'sms',
            'template' => $template,
            'recipient' => $phone,
            'body' => $this->redact($body, $ctx['secrets'] ?? []),
            'status' => 'logged',
        ]);
        if (self::mailConfig()['driver'] === 'log') {
            $this->writeLog('sms', $phone, '', $body);
        }
        return $id;
    }

    /** @param array<string,mixed> $cfg mailConfig() */
    private function sendSmtp(array $cfg, string $to, string $subject, string $body): void
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) $cfg['host'];
        $m->Port = (int) $cfg['port'];
        $m->SMTPAuth = $cfg['username'] !== '';
        $m->Username = (string) $cfg['username'];
        $m->Password = (string) $cfg['password'];
        $m->Timeout = 15;
        if ($cfg['encryption'] === 'none') {
            $m->SMTPSecure = '';
            $m->SMTPAutoTLS = false;
        } else {
            $m->SMTPSecure = $cfg['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        }
        $m->CharSet = 'UTF-8';
        $m->setFrom((string) $cfg['from_email'], (string) $cfg['from_name']);
        if ($cfg['reply_to'] !== '' && filter_var($cfg['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $m->addReplyTo((string) $cfg['reply_to']);
        }
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->Body = $body;
        $m->isHTML(false);
        $m->send();
    }

    /** Development transport: full message (including links) in a local, non-public log. */
    private function writeLog(string $channel, string $to, string $subject, string $body): void
    {
        $dir = App::root() . '/storage/logs';
        @file_put_contents(
            $dir . '/mail-' . date('Y-m-d') . '.log',
            sprintf("==== %s %s to %s ====\n%s%s\n\n", date('Y-m-d H:i:s'), strtoupper($channel), $to, $subject !== '' ? "Subject: $subject\n\n" : '', $body),
            FILE_APPEND | LOCK_EX
        );
    }

    /** @param list<string> $secrets */
    private function redact(string $body, array $secrets): string
    {
        foreach ($secrets as $s) {
            if ($s !== '') {
                $body = str_replace($s, '[redacted]', $body);
            }
        }
        return $body;
    }
}
