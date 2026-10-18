<?php

declare(strict_types=1);

namespace Waimart\Services;

use PHPMailer\PHPMailer\PHPMailer;
use Waimart\Core\App;
use Waimart\Core\Logger;

/**
 * Outbound notifications. Every message is recorded in `notifications` (with secrets
 * redacted). Email goes out via SMTP when configured; in development the "log" driver writes
 * the full message to storage/logs/mail-*.log instead. SMS is stored in the outbox only
 * (no SMS provider configured — see docs).
 */
final class NotificationService
{
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

        $driver = (string) config('mail.driver', 'log');
        try {
            if ($driver === 'smtp') {
                $this->sendSmtp($to, $subject, $body);
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
        if ((string) config('mail.driver', 'log') === 'log') {
            $this->writeLog('sms', $phone, '', $body);
        }
        return $id;
    }

    private function sendSmtp(string $to, string $subject, string $body): void
    {
        $cfg = (array) config('mail.smtp', []);
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) $cfg['host'];
        $m->Port = (int) ($cfg['port'] ?? 587);
        $m->SMTPAuth = ($cfg['username'] ?? '') !== '';
        $m->Username = (string) ($cfg['username'] ?? '');
        $m->Password = (string) ($cfg['password'] ?? '');
        $m->SMTPSecure = ($cfg['encryption'] ?? 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $m->CharSet = 'UTF-8';
        $m->setFrom((string) config('mail.from_email'), (string) (config('mail.from_name') ?: brand_name()));
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
