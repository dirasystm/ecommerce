<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\HttpException;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Seller payouts, recorded manually (no automatic bank or M-Pesa disbursement):
 *   requested (seller) → approved (staff) → paid (staff records method + reference + amount after paying)
 *   requested → cancelled (seller);  requested/approved → rejected (staff, with reason)
 *
 * Money in the ledger: a request moves the amount available → reserved, so a second request can only use what
 * is left; rejection/cancellation moves it back; payment moves it reserved → paid. The seller row is locked for
 * the request and the database allows one open request per seller, so concurrent requests cannot overdraw.
 */
final class PayoutService
{
    public const METHODS = ['mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'];

    private Database $db;
    private SellerLedgerService $ledger;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
        $this->ledger = new SellerLedgerService($this->db);
    }

    public static function minimum(): string
    {
        return Money::normalize((string) (Settings::get('payout_min_amount', '500') ?: '0'));
    }

    /** @return array<string,mixed> the new payout request */
    public function request(int $sellerId, int $userId, string $amount): array
    {
        $amount = trim($amount);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount)) {
            throw new ValidationException(['amount' => 'Enter an amount like 2500 or 2500.50.']);
        }
        $amount = Money::normalize($amount);
        $id = $this->db->transaction(function () use ($sellerId, $userId, $amount) {
            $seller = $this->db->one('SELECT id, status, name FROM sellers WHERE id = ? FOR UPDATE', [$sellerId]) ?? throw HttpException::notFound();
            if ($seller['status'] !== 'active') {
                throw ValidationException::withMessage('Payouts cannot be requested while the store is suspended.');
            }
            if ($this->db->value("SELECT 1 FROM seller_payouts WHERE seller_id = ? AND status IN ('requested','approved')", [$sellerId])) {
                throw ValidationException::withMessage('You already have a payout request in progress. Wait for it to be paid or cancel it first.');
            }
            $available = $this->ledger->balances($sellerId)['available'];
            if (Money::cmp($amount, self::minimum()) < 0) {
                throw new ValidationException(['amount' => 'The minimum payout is ' . Money::format(self::minimum()) . '.']);
            }
            if (Money::cmp($amount, $available) > 0) {
                throw new ValidationException(['amount' => 'You can request at most ' . Money::format($available) . ' (your available balance).']);
            }
            $method = $this->db->one('SELECT id, masked FROM seller_payout_methods WHERE seller_id = ? AND is_active = 1', [$sellerId])
                ?? throw ValidationException::withMessage('Add payout details before requesting a payout (contact Waimart Seller Support).');
            $id = $this->db->insert('seller_payouts', [
                'payout_number' => $this->number(), 'seller_id' => $sellerId, 'amount' => $amount, 'status' => 'requested',
                'payout_method_id' => $method['id'], 'destination_masked' => $method['masked'], 'requested_by' => $userId,
            ]);
            $ctx = ['payout_id' => $id, 'actor_user_id' => $userId, 'note' => 'Payout requested'];
            $this->ledger->post($sellerId, 'payout_request', 'available', '-' . $amount, "payout:$id:request:available", $ctx);
            $this->ledger->post($sellerId, 'payout_request', 'reserved', $amount, "payout:$id:request:reserved", $ctx);
            $this->record($id, null, 'requested', $userId, null);
            return $id;
        });
        $p = $this->find($id);
        if ($ops = (string) Settings::get('store_email', '')) {
            (new NotificationService())->email($ops, 'Payout request ' . $p['payout_number'], "{$p['seller_name']} requested a payout of " . Money::format((string) $p['amount']) . ".\n"
                . config('app.url') . '/admin/payouts/' . $id, 'payout_requested_staff');
        }
        return $p;
    }

    public function cancel(int $sellerId, int $userId, int $payoutId): void
    {
        $this->db->transaction(function () use ($sellerId, $userId, $payoutId) {
            $p = $this->lock($payoutId);
            if ((int) $p['seller_id'] !== $sellerId) {
                throw HttpException::notFound();
            }
            $this->move($p, 'requested', 'cancelled', $userId, 'Cancelled by seller');
            $this->returnToAvailable($p, $userId, 'Payout request cancelled');
        });
    }

    public function approve(int $payoutId, int $staffId, ?string $note = null): void
    {
        $this->db->transaction(function () use ($payoutId, $staffId, $note) {
            $p = $this->lock($payoutId);
            $this->move($p, 'requested', 'approved', $staffId, $note, ['decided_by' => $staffId, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => $note]);
        });
        AuditLogger::log('payout.approved', 'seller_payout', $payoutId, ['status' => 'requested'], ['status' => 'approved', 'note' => $note], $staffId);
    }

    public function reject(int $payoutId, int $staffId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'Give the seller a reason (at least 5 characters).']);
        }
        $p = $this->db->transaction(function () use ($payoutId, $staffId, $reason) {
            $p = $this->lock($payoutId);
            $this->move($p, ['requested', 'approved'], 'rejected', $staffId, $reason, ['decided_by' => $staffId, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => mb_substr($reason, 0, 500)]);
            $this->returnToAvailable($p, $staffId, 'Payout rejected: ' . $reason);
            return $p;
        });
        AuditLogger::log('payout.rejected', 'seller_payout', $payoutId, ['status' => $p['status']], ['status' => 'rejected', 'reason' => $reason], $staffId);
        $this->notifySeller($payoutId, 'payout_rejected', 'Your payout request was not approved', "Your payout request {$p['payout_number']} (" . Money::format((string) $p['amount']) . ") was not approved.\n\nReason: $reason\n\nThe amount is back in your available balance.");
    }

    /** Records a payout that has ACTUALLY been made, with its payment reference. Only then is it "paid". */
    public function markPaid(int $payoutId, int $staffId, string $method, string $reference, string $amountPaid): void
    {
        $reference = strtoupper(trim($reference));
        if (!isset(self::METHODS[$method])) {
            throw new ValidationException(['paid_method' => 'Choose how the payout was paid.']);
        }
        if (!preg_match('/^[A-Z0-9\/._-]{4,80}$/', $reference)) {
            throw new ValidationException(['paid_reference' => 'Enter the payment reference (e.g. the M-Pesa transaction code or bank reference).']);
        }
        $p = $this->db->transaction(function () use ($payoutId, $staffId, $method, $reference, $amountPaid) {
            $p = $this->lock($payoutId);
            if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($amountPaid)) || Money::cmp(Money::normalize(trim($amountPaid)), (string) $p['amount']) !== 0) {
                throw new ValidationException(['paid_amount' => 'The amount paid must equal the approved payout of ' . Money::format((string) $p['amount']) . '.']);
            }
            if ($this->db->value('SELECT 1 FROM seller_payouts WHERE paid_method = ? AND paid_reference = ? AND id <> ?', [$method, $reference, $payoutId])) {
                throw new ValidationException(['paid_reference' => 'This payment reference has already been used for another payout.']);
            }
            $this->move($p, 'approved', 'paid', $staffId, 'Paid: ' . $reference, [
                'paid_amount' => $p['amount'], 'paid_method' => $method, 'paid_reference' => $reference, 'paid_by' => $staffId, 'paid_at' => date('Y-m-d H:i:s'),
            ]);
            $ctx = ['payout_id' => $payoutId, 'actor_user_id' => $staffId, 'note' => 'Paid by ' . self::METHODS[$method] . ', ref ' . $reference];
            $this->ledger->post((int) $p['seller_id'], 'payout_paid', 'reserved', '-' . $p['amount'], "payout:$payoutId:paid:reserved", $ctx);
            $this->ledger->post((int) $p['seller_id'], 'payout_paid', 'paid', (string) $p['amount'], "payout:$payoutId:paid:paid", $ctx);
            return $p;
        });
        AuditLogger::log('payout.paid', 'seller_payout', $payoutId, ['status' => 'approved'], ['status' => 'paid', 'method' => $method, 'reference' => $reference, 'amount' => $p['amount']], $staffId);
        $this->notifySeller($payoutId, 'payout_paid', 'Payout sent: ' . Money::format((string) $p['amount']),
            "We have paid your payout {$p['payout_number']} of " . Money::format((string) $p['amount']) . ' to ' . $p['destination_masked'] . ".\nPayment reference: $reference");
    }

    /** @return array<string,mixed> */
    public function find(int $payoutId): array
    {
        return $this->db->one(
            'SELECT p.*, s.name AS seller_name, s.slug AS seller_slug, rq.first_name AS requested_first, rq.last_name AS requested_last,
                    dc.first_name AS decided_first, dc.last_name AS decided_last, pd.first_name AS paid_first, pd.last_name AS paid_last
               FROM seller_payouts p JOIN sellers s ON s.id = p.seller_id
               LEFT JOIN users rq ON rq.id = p.requested_by LEFT JOIN users dc ON dc.id = p.decided_by LEFT JOIN users pd ON pd.id = p.paid_by
              WHERE p.id = ?',
            [$payoutId]
        ) ?? throw HttpException::notFound();
    }

    /** @return list<array<string,mixed>> */
    public function list(?int $sellerId, ?string $status, int $limit = 300): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($sellerId !== null) {
            $where[] = 'p.seller_id = ?';
            $params[] = $sellerId;
        }
        if ($status !== null) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        return $this->db->all(
            'SELECT p.*, s.name AS seller_name FROM seller_payouts p JOIN sellers s ON s.id = p.seller_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.requested_at DESC LIMIT ' . max(1, min(1000, $limit)),
            $params
        );
    }

    private function record(int $payoutId, ?string $from, string $to, ?int $actor, ?string $note): void
    {
        $this->db->insert('seller_payout_history', ['payout_id' => $payoutId, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null]);
    }

    /** @return list<array<string,mixed>> */
    public function history(int $payoutId): array
    {
        return $this->db->all('SELECT h.*, u.first_name, u.last_name FROM seller_payout_history h LEFT JOIN users u ON u.id = h.actor_user_id WHERE h.payout_id = ? ORDER BY h.id', [$payoutId]);
    }

    /**
     * Full payout destination for staff who will make the payment. Every view is audited.
     * @return array<string,mixed>|null
     */
    public function destinationFor(int $payoutId, int $staffId): ?array
    {
        $enc = $this->db->value('SELECT m.details_enc FROM seller_payouts p JOIN seller_payout_methods m ON m.id = p.payout_method_id WHERE p.id = ?', [$payoutId]);
        if (!$enc) {
            return null;
        }
        AuditLogger::log('payout.destination_viewed', 'seller_payout', $payoutId, null, null, $staffId);
        $d = json_decode((string) Crypto::decrypt((string) $enc), true);
        return is_array($d) ? $d : null;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM seller_payouts WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound();
    }

    /** Guarded status change + history. @param string|list<string> $from @param array<string,mixed> $sets */
    private function move(array $p, string|array $from, string $to, int $actor, ?string $note, array $sets = []): void
    {
        $from = (array) $from;
        if (!in_array($p['status'], $from, true)) {
            throw ValidationException::withMessage('This payout is "' . $p['status'] . '" and cannot be changed to "' . $to . '".');
        }
        $cols = ['status = ?'];
        $params = [$to];
        foreach ($sets as $k => $v) {
            $cols[] = "`$k` = ?";
            $params[] = $v;
        }
        array_push($params, $p['id'], $p['status']);
        if ($this->db->exec('UPDATE seller_payouts SET ' . implode(', ', $cols) . ' WHERE id = ? AND status = ?', $params) !== 1) {
            throw ValidationException::withMessage('This payout changed in the meantime. Please reload.');
        }
        $this->record((int) $p['id'], (string) $p['status'], $to, $actor, $note);
    }

    private function returnToAvailable(array $p, int $actor, string $note): void
    {
        $ctx = ['payout_id' => $p['id'], 'actor_user_id' => $actor, 'note' => mb_substr($note, 0, 500)];
        $this->ledger->post((int) $p['seller_id'], 'payout_return', 'reserved', '-' . $p['amount'], "payout:{$p['id']}:return:reserved", $ctx);
        $this->ledger->post((int) $p['seller_id'], 'payout_return', 'available', (string) $p['amount'], "payout:{$p['id']}:return:available", $ctx);
    }

    private function number(): string
    {
        do {
            $n = 'PO-' . Crypto::code(8);
        } while ($this->db->value('SELECT 1 FROM seller_payouts WHERE payout_number = ?', [$n]));
        return $n;
    }

    private function notifySeller(int $payoutId, string $template, string $subject, string $body): void
    {
        $sellerId = (int) $this->db->value('SELECT seller_id FROM seller_payouts WHERE id = ?', [$payoutId]);
        foreach ($this->db->all("SELECT u.id, u.email, u.first_name FROM seller_users su JOIN users u ON u.id = su.user_id WHERE su.seller_id = ? AND su.role = 'owner'", [$sellerId]) as $u) {
            (new NotificationService())->email((string) $u['email'], $subject, "Hello {$u['first_name']},\n\n$body\n\n— Waimart Seller Support", $template, ['user_id' => (int) $u['id']]);
        }
    }
}
