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
                $ctx = ['user_id' => (int) $u['id'], 'order_id' => $orderId];
                if ($important || (int) $u['notify_email'] === 1) {
                    $svc->email((string) $u['email'], $subject, "Hello {$u['first_name']},\n\n$body\n\n— " . brand_name() . " Seller Support", $template, $ctx);
                    $n++;
                }
                if (!$important && (int) $u['notify_sms'] === 1 && (string) $u['phone'] !== '') {
                    $svc->sms((string) $u['phone'], brand_name() . " Seller Centre: $subject", $template, $ctx);
                    $n++;
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Seller notification failed', ['seller_id' => $sellerId, 'template' => $template, 'error' => $e->getMessage()]);
        }
        return $n;
    }
}
