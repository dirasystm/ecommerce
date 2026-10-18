<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\RateLimiter;
use Waimart\Core\ValidationException;

/**
 * Guest-to-account order linking (D3). Ownership is proven by a one-time code sent to the
 * phone/email recorded on the order — never by merely typing a matching email.
 * Responses are identical whether or not the order exists or can be linked.
 */
final class OrderClaimService
{
    private const TTL_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return int|null claim id (null when nothing was sent; caller must not reveal which) */
    public function start(int $userId, string $orderNumber, string $ip): ?int
    {
        $rl = new RateLimiter($this->db);
        if (!$rl->attempt('claim-user|' . $userId, 5, 3600) || !$rl->attempt('claim-ip|' . $ip, 20, 3600)) {
            throw ValidationException::withMessage('Too many attempts. Please try again in an hour.');
        }
        $orderNumber = strtoupper(trim($orderNumber));
        $order = preg_match(Brand::ORDER_NUMBER_REGEX, $orderNumber)
            ? $this->db->one('SELECT * FROM orders WHERE order_number = ? AND user_id IS NULL', [$orderNumber])
            : null;
        if ($order === null) {
            return null;
        }
        $code = (string) random_int(100000, 999999);
        $claimId = $this->db->transaction(function () use ($userId, $order, $code) {
            // Only the latest code for this user/order works.
            $this->db->exec(
                'UPDATE order_claims SET expires_at = NOW() WHERE user_id = ? AND order_id = ? AND verified_at IS NULL AND expires_at > NOW()',
                [$userId, $order['id']]
            );
            return $this->db->insert('order_claims', [
                'user_id' => $userId,
                'order_id' => $order['id'],
                'channel' => $order['customer_email'] ? 'email' : 'sms',
                'code_hash' => Crypto::hash($code),
                'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
            ]);
        });

        $msg = "Your " . brand_name() . " verification code to add order {$order['order_number']} to your account is $code. "
            . 'It expires in ' . self::TTL_MINUTES . ' minutes. If you did not request this, ignore this message.';
        $n = new NotificationService();
        $ctx = ['order_id' => (int) $order['id'], 'secrets' => [$code]];
        $n->sms((string) $order['customer_phone'], $msg, 'order_claim_code', $ctx);
        if ($order['customer_email']) {
            $n->email((string) $order['customer_email'], 'Your ' . brand_name() . ' verification code', $msg, 'order_claim_code', $ctx);
        }
        return $claimId;
    }

    /** @return string the linked order number */
    public function verify(int $userId, ?int $claimId, string $code): string
    {
        $generic = 'That code is incorrect or has expired. Please request a new code.';
        if ($claimId === null || !preg_match('/^\d{6}$/', trim($code))) {
            throw new ValidationException(['code' => $generic]);
        }
        // The transaction returns an outcome instead of throwing, so a failed attempt is committed
        // (throwing inside would roll back the attempt counter and defeat the limit).
        $outcome = $this->db->transaction(function () use ($userId, $claimId, $code) {
            $c = $this->db->one('SELECT * FROM order_claims WHERE id = ? AND user_id = ? FOR UPDATE', [$claimId, $userId]);
            if ($c === null || $c['verified_at'] !== null || strtotime((string) $c['expires_at']) <= time()
                || (int) $c['attempts'] >= self::MAX_ATTEMPTS) {
                return ['ok' => false];
            }
            if (!hash_equals((string) $c['code_hash'], Crypto::hash(trim($code)))) {
                $this->db->exec('UPDATE order_claims SET attempts = attempts + 1 WHERE id = ?', [$claimId]);
                return ['ok' => false];
            }
            $this->db->exec('UPDATE order_claims SET verified_at = NOW() WHERE id = ?', [$claimId]);
            $linked = $this->db->exec('UPDATE orders SET user_id = ? WHERE id = ? AND user_id IS NULL', [$userId, $c['order_id']]);
            if ($linked !== 1) {
                return ['ok' => false, 'message' => 'This order is already linked to an account.'];
            }
            AuditLogger::log('order.linked_to_account', 'order', (int) $c['order_id'], null, ['user_id' => $userId, 'via' => $c['channel']], $userId);
            return ['ok' => true, 'number' => (string) $this->db->value('SELECT order_number FROM orders WHERE id = ?', [$c['order_id']])];
        });
        if (!$outcome['ok']) {
            throw new ValidationException(['code' => $outcome['message'] ?? $generic]);
        }
        return $outcome['number'];
    }
}
