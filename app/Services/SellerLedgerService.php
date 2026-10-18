<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;
use Waimart\Core\Money;
use Waimart\Core\Settings;

/**
 * The seller money ledger (seller_ledger_entries): append-only, signed amounts in four buckets.
 *
 *   pending    earnings accrued from money Waimart has RECEIVED (verified M-Pesa / recorded COD cash), not yet payable
 *   available  earnings that met the payout policy and can be requested
 *   reserved   amounts in an open payout request (so concurrent requests can never spend the same money twice)
 *   paid       amounts actually paid out (recorded with the payment reference)
 *
 * A balance is always SUM(amount) per bucket — no stored balance exists. Every posting has a unique posting_key, so
 * repeating an event (retries, double clicks, workers racing) can never post twice. Rows are never updated or deleted;
 * corrections are new rows. The Waimart Official Store and pre-marketplace (legacy) orders are not in this ledger.
 */
final class SellerLedgerService
{
    public const BUCKETS = ['pending', 'available', 'reserved', 'paid'];
    public const BUCKET_LABELS = ['pending' => 'Pending', 'available' => 'Available', 'reserved' => 'In payout requests', 'paid' => 'Paid out'];
    public const TYPE_LABELS = [
        'sale' => 'Sale', 'commission' => 'Waimart commission', 'release' => 'Became payable', 'refund' => 'Refund to customer',
        'commission_reversal' => 'Commission reversed', 'cancellation' => 'Sale reversed (not supplied)', 'payout_request' => 'Payout requested',
        'payout_return' => 'Payout request returned', 'payout_paid' => 'Payout paid', 'correction' => 'Adjustment',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array{pending:string,available:string,reserved:string,paid:string} */
    public function balances(int $sellerId): array
    {
        $rows = array_column($this->db->all('SELECT bucket, SUM(amount) AS total FROM seller_ledger_entries WHERE seller_id = ? GROUP BY bucket', [$sellerId]), 'total', 'bucket');
        $out = [];
        foreach (self::BUCKETS as $b) {
            $out[$b] = Money::normalize((string) ($rows[$b] ?? '0'));
        }
        return $out;
    }

    /**
     * Seller performance figures, all derived from the ledger and order snapshots.
     * @return array{gross_sales:string,commission:string,net_earnings:string,reversed:string}
     */
    public function summary(int $sellerId): array
    {
        $by = array_column($this->db->all(
            "SELECT entry_type, SUM(amount) AS total FROM seller_ledger_entries WHERE seller_id = ? AND entry_type IN ('sale','commission','cancellation','commission_reversal','refund','correction') GROUP BY entry_type",
            [$sellerId]
        ), 'total', 'entry_type');
        $sale = (string) ($by['sale'] ?? '0');
        $commission = Money::normalize(bcmul((string) ($by['commission'] ?? '0'), '-1', 2));
        // Everything that is not a sale, commission or a move between balances: reversals, refunds and adjustments.
        $reversed = Money::add((string) ($by['cancellation'] ?? '0'), (string) ($by['commission_reversal'] ?? '0'), (string) ($by['refund'] ?? '0'), (string) ($by['correction'] ?? '0'));
        return [
            'gross_sales' => Money::normalize($sale),
            'commission' => $commission,
            'reversed' => Money::normalize($reversed),
            'net_earnings' => Money::add(Money::sub($sale, $commission), $reversed),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function entries(int $sellerId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT e.*, so.sub_number, p.payout_number FROM seller_ledger_entries e
               LEFT JOIN seller_orders so ON so.id = e.seller_order_id LEFT JOIN seller_payouts p ON p.id = e.payout_id
              WHERE e.seller_id = ? ORDER BY e.id DESC LIMIT ' . max(1, min(1000, $limit)),
            [$sellerId]
        );
    }

    /**
     * Money for this order has been received (M-Pesa verified at confirmation, or COD cash recorded): each
     * marketplace part that is still being supplied accrues its earnings as PENDING. Idempotent.
     */
    public function accrueOrder(int $orderId, ?int $actorUserId = null): int
    {
        $parts = $this->db->all(
            "SELECT so.* FROM seller_orders so JOIN sellers s ON s.id = so.seller_id
              WHERE so.order_id = ? AND s.is_platform = 0 AND so.is_legacy = 0 AND so.earnings_status = 'not_accrued'
                AND so.status NOT IN ('rejected','cancelled')",
            [$orderId]
        );
        foreach ($parts as $so) {
            $this->post((int) $so['seller_id'], 'sale', 'pending', (string) $so['items_subtotal'], "sale:so:{$so['id']}", ['seller_order_id' => $so['id'], 'actor_user_id' => $actorUserId, 'note' => 'Sale ' . $so['sub_number']]);
            if (Money::cmp((string) $so['commission_total'], '0') > 0) {
                $this->post((int) $so['seller_id'], 'commission', 'pending', '-' . $so['commission_total'], "commission:so:{$so['id']}", ['seller_order_id' => $so['id'], 'note' => 'Commission on ' . $so['sub_number']]);
            }
            if (Money::cmp((string) $so['seller_discount'], '0') > 0) {
                $this->post((int) $so['seller_id'], 'correction', 'pending', '-' . $so['seller_discount'], "seller_discount:so:{$so['id']}", ['seller_order_id' => $so['id'], 'note' => 'Seller-funded discount']);
            }
            $this->db->exec("UPDATE seller_orders SET earnings_status = 'pending' WHERE id = ? AND earnings_status = 'not_accrued'", [$so['id']]);
        }
        return count($parts);
    }

    /** A part that will not be supplied: its pending earnings are reversed (sale and commission both). Idempotent. */
    public function reversePart(int $sellerOrderId, string $reason, ?int $actorUserId = null): void
    {
        $so = $this->db->one('SELECT * FROM seller_orders WHERE id = ? FOR UPDATE', [$sellerOrderId]);
        if ($so === null || $so['earnings_status'] !== 'pending') {
            return;   // never accrued (nothing received) or already reversed
        }
        $sale = $this->db->one("SELECT id, amount FROM seller_ledger_entries WHERE posting_key = ?", ["sale:so:$sellerOrderId"]);
        $com = $this->db->one("SELECT id, amount FROM seller_ledger_entries WHERE posting_key = ?", ["commission:so:$sellerOrderId"]);
        if ($sale !== null) {
            $this->post((int) $so['seller_id'], 'cancellation', 'pending', Money::normalize(bcmul((string) $sale['amount'], '-1', 2)), "cancel:so:$sellerOrderId:sale",
                ['seller_order_id' => $sellerOrderId, 'reverses_entry_id' => $sale['id'], 'actor_user_id' => $actorUserId, 'note' => mb_substr('Not supplied: ' . $reason, 0, 500)]);
        }
        if ($com !== null) {
            $this->post((int) $so['seller_id'], 'commission_reversal', 'pending', Money::normalize(bcmul((string) $com['amount'], '-1', 2)), "cancel:so:$sellerOrderId:commission",
                ['seller_order_id' => $sellerOrderId, 'reverses_entry_id' => $com['id'], 'actor_user_id' => $actorUserId, 'note' => 'Commission reversed']);
        }
        $this->db->exec("UPDATE seller_orders SET earnings_status = 'reversed' WHERE id = ?", [$sellerOrderId]);
    }

    /**
     * A customer refund on a part the seller has earnings for (return or dispute): the seller gives back the matching
     * share of the sale and Waimart gives back the same share of its commission. The share is the refund's fraction
     * of what the customer paid for the part, so a platform-funded discount is never charged to the seller.
     *
     * Bucket: while the part's earnings are still pending the adjustment stays pending (and is netted when released);
     * once they are payable it comes out of AVAILABLE — which may go below zero if the seller was already paid out.
     * A negative available balance is money the seller owes; it is recovered from later earnings, and payouts are
     * impossible until it is covered. Idempotent per refund.
     * @return bool whether anything was posted
     */
    public function refundPart(int $sellerOrderId, int $refundId, string $amount, ?int $actorUserId = null): bool
    {
        $so = $this->db->one(
            'SELECT so.*, s.is_platform FROM seller_orders so JOIN sellers s ON s.id = so.seller_id WHERE so.id = ? FOR UPDATE',
            [$sellerOrderId]
        );
        if ($so === null || (int) $so['is_platform'] === 1 || (int) $so['is_legacy'] === 1 || !in_array($so['earnings_status'], ['pending', 'available'], true)) {
            return false;   // Waimart's own sales, pre-marketplace orders, or nothing earned (never paid / already reversed)
        }
        $paid = Money::sub((string) $so['items_subtotal'], (string) $so['discount_share']);
        if (Money::cmp($paid, '0') <= 0) {
            return false;
        }
        $f = bcdiv($amount, $paid, 10);
        if (bccomp($f, '1', 10) > 0) {
            $f = '1';
        }
        // Cap the cumulative adjustments at what was accrued for the part (rounding can never over-charge).
        $done = array_column($this->db->all(
            "SELECT entry_type, SUM(amount) AS t FROM seller_ledger_entries WHERE seller_order_id = ? AND entry_type IN ('refund','commission_reversal') AND posting_key LIKE 'refund:%' GROUP BY entry_type",
            [$sellerOrderId]
        ), 't', 'entry_type');
        $saleLeft = Money::add((string) $so['items_subtotal'], (string) ($done['refund'] ?? '0'));
        $comLeft = Money::sub((string) $so['commission_total'], (string) ($done['commission_reversal'] ?? '0'));
        $sale = Money::round(bcmul((string) $so['items_subtotal'], $f, 10));
        $com = Money::round(bcmul((string) $so['commission_total'], $f, 10));
        $sale = Money::cmp($sale, $saleLeft) > 0 ? $saleLeft : $sale;
        $com = Money::cmp($com, $comLeft) > 0 ? $comLeft : $com;

        $bucket = $so['earnings_status'] === 'pending' ? 'pending' : 'available';
        $ctx = ['seller_order_id' => $sellerOrderId, 'refund_id' => $refundId, 'actor_user_id' => $actorUserId];
        $a = $this->post((int) $so['seller_id'], 'refund', $bucket, Money::normalize(bcmul($sale, '-1', 2)), "refund:$refundId:sale", $ctx + ['note' => 'Customer refund on ' . $so['sub_number']]);
        $b = $this->post((int) $so['seller_id'], 'commission_reversal', $bucket, $com, "refund:$refundId:commission", $ctx + ['note' => 'Commission returned on refund']);
        return $a || $b;
    }

    /** A refund obligation was cancelled (never paid): its ledger adjustment is reversed. Idempotent. */
    public function cancelRefund(int $refundId, ?int $actorUserId = null): void
    {
        $rows = $this->db->all("SELECT * FROM seller_ledger_entries WHERE posting_key IN (?, ?)", ["refund:$refundId:sale", "refund:$refundId:commission"]);
        foreach ($rows as $e) {
            $so = $this->db->one('SELECT earnings_status FROM seller_orders WHERE id = ? FOR UPDATE', [$e['seller_order_id']]);
            $bucket = ($so['earnings_status'] ?? '') === 'pending' ? 'pending' : 'available';
            $this->post((int) $e['seller_id'], 'correction', $bucket, Money::normalize(bcmul((string) $e['amount'], '-1', 2)), $e['posting_key'] . ':cancel', [
                'seller_order_id' => $e['seller_order_id'], 'refund_id' => $refundId, 'reverses_entry_id' => $e['id'], 'actor_user_id' => $actorUserId,
                'note' => 'Refund cancelled',
            ]);
        }
    }

    /**
     * Moves earnings that meet the payout policy from pending to available. Run by the scheduled worker; safe to
     * run concurrently (posting keys). Policy (settings): payment received (implied by accrual) AND, when
     * `payout_requires_fulfilment` is on, the part delivered/collected; AND `payout_hold_days` passed since then
     * (or since accrual); AND no open dispute on the part and no open return on the order.
     * @return int parts released
     */
    public function releaseEligible(int $limit = 500): int
    {
        $hold = max(0, Settings::int('payout_hold_days', 7));
        $needFulfilment = Settings::get('payout_requires_fulfilment', '1') !== '0';
        $since = $needFulfilment ? 'COALESCE(so.delivered_at, so.collected_at)' : '(SELECT MIN(created_at) FROM seller_ledger_entries x WHERE x.posting_key = CONCAT(\'sale:so:\', so.id))';
        $rows = $this->db->all(
            "SELECT so.id FROM seller_orders so
              WHERE so.earnings_status = 'pending'
                " . ($needFulfilment ? "AND so.status IN ('delivered','collected')" : "AND so.status NOT IN ('rejected','cancelled')") . "
                AND $since IS NOT NULL AND $since <= NOW() - INTERVAL $hold DAY
                AND NOT EXISTS (SELECT 1 FROM seller_disputes d WHERE d.seller_order_id = so.id AND d.status IN ('open','awaiting_seller','awaiting_customer'))
                AND NOT EXISTS (SELECT 1 FROM return_requests r WHERE r.order_id = so.order_id AND r.status IN ('requested','approved','received')
                                  AND (r.seller_order_id IS NULL OR r.seller_order_id = so.id))
              ORDER BY so.id LIMIT " . max(1, $limit)
        );
        $n = 0;
        foreach ($rows as $r) {
            try {
                $n += $this->db->transaction(fn () => $this->releasePart((int) $r['id']));
            } catch (\Throwable $e) {
                Logger::error('Earnings release failed', ['seller_order_id' => $r['id'], 'error' => $e->getMessage()]);
            }
        }
        return $n;
    }

    private function releasePart(int $sellerOrderId): int
    {
        $so = $this->db->one("SELECT * FROM seller_orders WHERE id = ? AND earnings_status = 'pending' FOR UPDATE", [$sellerOrderId]);
        if ($so === null) {
            return 0;
        }
        $net = Money::normalize((string) $this->db->value("SELECT COALESCE(SUM(amount), 0) FROM seller_ledger_entries WHERE seller_order_id = ? AND bucket = 'pending'", [$sellerOrderId]));
        if (Money::cmp($net, '0') !== 0) {
            $ctx = ['seller_order_id' => $sellerOrderId, 'note' => 'Payable: ' . $so['sub_number']];
            $this->post((int) $so['seller_id'], 'release', 'pending', Money::normalize(bcmul($net, '-1', 2)), "release:so:$sellerOrderId:pending", $ctx);
            $this->post((int) $so['seller_id'], 'release', 'available', $net, "release:so:$sellerOrderId:available", $ctx);
        }
        $this->db->exec("UPDATE seller_orders SET earnings_status = 'available', earnings_eligible_at = NOW() WHERE id = ?", [$sellerOrderId]);
        return 1;
    }

    /**
     * One ledger row. Duplicate posting keys are rejected by the database (uq_sle_posting) — callers treat a
     * duplicate as "already posted" so retries are harmless.
     * @param array<string,mixed> $ctx
     */
    public function post(int $sellerId, string $type, string $bucket, string $amount, string $key, array $ctx = []): bool
    {
        if (Money::cmp($amount, '0') === 0) {
            return false;
        }
        try {
            $this->db->insert('seller_ledger_entries', [
                'seller_id' => $sellerId, 'entry_type' => $type, 'bucket' => $bucket, 'amount' => Money::normalize($amount), 'posting_key' => $key,
                'seller_order_id' => $ctx['seller_order_id'] ?? null, 'order_item_id' => $ctx['order_item_id'] ?? null,
                'payout_id' => $ctx['payout_id'] ?? null, 'refund_id' => $ctx['refund_id'] ?? null,
                'reverses_entry_id' => $ctx['reverses_entry_id'] ?? null, 'actor_user_id' => $ctx['actor_user_id'] ?? null,
                'note' => isset($ctx['note']) ? mb_substr((string) $ctx['note'], 0, 500) : null,
            ]);
            return true;
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_sle_posting')) {
                return false;
            }
            throw $e;
        }
    }
}
