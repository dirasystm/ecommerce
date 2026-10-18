<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Seller stores after approval: admin oversight (suspend, reactivate, verified badge) and the seller's own
 * public profile. Suspension blocks new selling activity but keeps every order, payment and financial record.
 */
final class SellerService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT s.*, c.name AS county_name, a.first_name AS approver_first, a.last_name AS approver_last
               FROM sellers s LEFT JOIN counties c ON c.id = s.county_id LEFT JOIN users a ON a.id = s.approved_by WHERE s.id = ?',
            [$id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        return $this->db->all(
            'SELECT s.id, s.name, s.slug, s.status, s.is_platform, s.is_verified, s.approved_at, s.created_at,
                    (SELECT COUNT(*) FROM products p WHERE p.seller_id = s.id) AS products,
                    (SELECT u.email FROM seller_users su JOIN users u ON u.id = su.user_id WHERE su.seller_id = s.id AND su.role = \'owner\' LIMIT 1) AS owner_email
               FROM sellers s' . ($status !== null ? ' WHERE s.status = ?' : '') . ' ORDER BY s.is_platform DESC, s.name',
            $status !== null ? [$status] : []
        );
    }

    /** @return list<array<string,mixed>> */
    public function members(int $sellerId): array
    {
        return $this->db->all(
            'SELECT su.role, su.created_at, u.id, u.first_name, u.last_name, u.email, u.status FROM seller_users su JOIN users u ON u.id = su.user_id
              WHERE su.seller_id = ? ORDER BY FIELD(su.role, \'owner\', \'manager\', \'staff\'), u.first_name',
            [$sellerId]
        );
    }

    /** @return array<string,mixed>|null masked payout destination only — details stay encrypted */
    public function activePayoutMethod(int $sellerId): ?array
    {
        return $this->db->one('SELECT id, method, masked, created_at FROM seller_payout_methods WHERE seller_id = ? AND is_active = 1', [$sellerId]);
    }

    public function suspend(int $sellerId, int $staffId, string $reason): void
    {
        $reason = trim($reason);
        Validator::validate(['reason' => $reason], ['reason' => 'required|min:5|max:500'], ['reason' => 'Reason']);
        $seller = $this->editable($sellerId);
        if ($seller['status'] !== 'active') {
            throw ValidationException::withMessage('Only an active store can be suspended.');
        }
        $this->db->exec("UPDATE sellers SET status = 'suspended', suspended_at = NOW(), suspension_reason = ? WHERE id = ? AND status = 'active'", [$reason, $sellerId]);
        AuditLogger::log('seller.suspended', 'seller', $sellerId, ['status' => 'active'], ['status' => 'suspended', 'reason' => $reason], $staffId);
        $this->notifyOwners($sellerId, 'seller_suspended', 'Your Waimart store has been suspended',
            "Your store \"{$seller['name']}\" has been suspended.\n\nReason: $reason\n\nWhile suspended, your products are not shown to customers and you cannot make changes. "
            . "Your existing orders and financial records remain available in the Seller Centre. Please contact Waimart to resolve this.");
    }

    public function reactivate(int $sellerId, int $staffId, string $note = ''): void
    {
        $seller = $this->editable($sellerId);
        if ($seller['status'] !== 'suspended') {
            throw ValidationException::withMessage('Only a suspended store can be reactivated.');
        }
        $this->db->exec("UPDATE sellers SET status = 'active', suspended_at = NULL, suspension_reason = NULL WHERE id = ? AND status = 'suspended'", [$sellerId]);
        AuditLogger::log('seller.reactivated', 'seller', $sellerId, ['status' => 'suspended', 'reason' => $seller['suspension_reason']],
            ['status' => 'active', 'note' => trim($note) ?: null], $staffId);
        $this->notifyOwners($sellerId, 'seller_reactivated', 'Your Waimart store is active again',
            "Your store \"{$seller['name']}\" has been reactivated. You can sign in to the Seller Centre and continue selling.");
    }

    public function setVerified(int $sellerId, int $staffId, bool $verified): void
    {
        $seller = $this->editable($sellerId);
        $this->db->exec('UPDATE sellers SET is_verified = ? WHERE id = ?', [$verified ? 1 : 0, $sellerId]);
        AuditLogger::log('seller.verification_changed', 'seller', $sellerId, ['is_verified' => (int) $seller['is_verified']], ['is_verified' => (int) $verified], $staffId);
    }

    /**
     * The seller edits their own public store profile. Name, address (slug), status, verification and ownership
     * are not editable here — only by Waimart staff — so a seller cannot rename into impersonation or move the store.
     * @param array<string,mixed> $in
     * @param array<string,mixed> $logo one $_FILES entry (optional)
     */
    public function updateProfile(int $sellerId, int $actorUserId, array $in, array $logo = []): void
    {
        Validator::validate($in, [
            'description' => 'nullable|max:2000', 'public_email' => 'nullable|email', 'public_phone' => 'nullable|phone',
            'town' => 'nullable|max:100', 'county_id' => 'nullable|int',
        ], ['public_email' => 'Public email', 'public_phone' => 'Public phone']);
        if (($in['county_id'] ?? '') !== '' && !$this->db->value('SELECT 1 FROM counties WHERE id = ?', [(int) $in['county_id']])) {
            throw new ValidationException(['county_id' => 'Select a valid county.']);
        }
        $before = (array) $this->db->one('SELECT description, public_email, public_phone, show_contacts, allow_promotions, county_id, town, logo_path FROM sellers WHERE id = ?', [$sellerId]);
        $after = [
            'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'public_email' => strtolower(trim((string) ($in['public_email'] ?? ''))) ?: null,
            'public_phone' => ($in['public_phone'] ?? '') !== '' ? Validator::normalizePhone((string) $in['public_phone']) : null,
            'show_contacts' => !empty($in['show_contacts']) ? 1 : 0,
            'allow_promotions' => !empty($in['allow_promotions']) ? 1 : 0,
            'county_id' => ($in['county_id'] ?? '') !== '' ? (int) $in['county_id'] : null,
            'town' => trim((string) ($in['town'] ?? '')) ?: null,
            'logo_path' => $before['logo_path'] ?? null,
        ];
        $images = new ImageService();
        if (($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $after['logo_path'] = $images->storeImage($logo, 'stores', 400);
        } elseif (!empty($in['remove_logo'])) {
            $after['logo_path'] = null;
        }
        $this->db->exec(
            'UPDATE sellers SET description = ?, public_email = ?, public_phone = ?, show_contacts = ?, allow_promotions = ?, county_id = ?, town = ?, logo_path = ? WHERE id = ?',
            [...array_values($after), $sellerId]
        );
        if (($before['logo_path'] ?? null) && $before['logo_path'] !== $after['logo_path']) {
            $images->delete((string) $before['logo_path']);
        }
        $changed = array_filter($after, fn ($v, $k) => (string) ($before[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            AuditLogger::log('seller.profile_updated', 'seller', $sellerId, array_intersect_key($before, $changed), $changed, $actorUserId);
        }
    }

    /** @return array<string,mixed> */
    private function editable(int $sellerId): array
    {
        $s = $this->find($sellerId) ?? throw \Waimart\Core\HttpException::notFound();
        if ((int) $s['is_platform'] === 1) {
            throw ValidationException::withMessage('The Waimart Official Store is managed by the platform and cannot be changed here.');
        }
        return $s;
    }

    private function notifyOwners(int $sellerId, string $template, string $subject, string $body): void
    {
        (new SellerNotifier($this->db))->send($sellerId, $template, $subject, $body, true, ['owner']);   // account decisions: always sent
    }
}
