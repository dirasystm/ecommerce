<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;

/**
 * Messages to a store's team. Store owners and managers receive operational messages (orders, returns, disputes,
 * product decisions) by email and/or SMS as chosen on Seller Centre → Notifications. Important messages — account
 * decisions, suspensions and money (payouts) — are always emailed, as that page promises.
 * A failure to queue a message is logged and never breaks the action that caused it.
 */
final class SellerNotifier
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @param list<string> $roles @return int messages queued */
    public function send(int $sellerId, string $template, string $subject, string $body, bool $important = false, array $roles = ['owner', 'manager'], ?int $orderId = null): int
    {
        $n = 0;
        try {
            $in = implode(',', array_fill(0, count($roles), '?'));
            $users = $this->db->all(
                "SELECT u.id, u.email, u.phone, u.first_name, u.notify_email, u.notify_sms FROM seller_users su
                   JOIN sellers s ON s.id = su.seller_id AND s.is_platform = 0
                   JOIN users u ON u.id = su.user_id AND u.status = 'active'
                  WHERE su.seller_id = ? AND su.role IN ($in)",
                array_merge([$sellerId], $roles)
            );
            $svc = new NotificationService();
            foreach ($users as $u) {
                // Layout and SMS wording: the seller_message template. Important messages are emailed regardless of
                // the person's preferences and never by SMS.
                $n += $svc->sendTemplate('seller_message', ['first_name' => (string) $u['first_name'], 'subject' => $subject, 'message' => $body] + MessageTemplates::contactVars(),
                    ['email' => $important || (int) $u['notify_email'] === 1 ? (string) $u['email'] : null,
                     'phone' => !$important && (int) $u['notify_sms'] === 1 ? (string) $u['phone'] : null],
                    ['user_id' => (int) $u['id'], 'order_id' => $orderId, 'log_as' => $template]);
            }
        } catch (\Throwable $e) {
            Logger::error('Seller notification failed', ['seller_id' => $sellerId, 'template' => $template, 'error' => $e->getMessage()]);
        }
        return $n;
    }
}
