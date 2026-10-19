<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Seller applications: draft → submitted → under review → approved | rejected (→ draft again if
 * resubmission is allowed). Applicant methods take the signed-in user's id and only ever touch that
 * user's own application; staff methods take the staff id and are routed behind sellers.* permissions.
 */
final class SellerApplicationService
{
    /** Store addresses that could impersonate the platform or collide with site paths. */
    public const RESERVED_SLUGS = ['admin', 'seller', 'sellers', 'sell', 'store', 'stores', 'shop', 'official', 'support',
        'help', 'account', 'login', 'register', 'checkout', 'cart', 'api', 'new', 'edit', 'settings', 'search'];
    public const MAX_DOCUMENTS = 10;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    // ------------------------------------------------------------------ reading

    /** @return array<string,mixed>|null the applicant's most recent application */
    public function latestFor(int $userId): ?array
    {
        return $this->db->one('SELECT * FROM seller_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            "SELECT a.*, c.name AS county_name, u.email AS account_email, u.first_name AS account_first, u.last_name AS account_last,
                    r.first_name AS reviewer_first, r.last_name AS reviewer_last
               FROM seller_applications a
               JOIN counties c ON c.id = a.county_id
               JOIN users u ON u.id = a.user_id
               LEFT JOIN users r ON r.id = a.reviewed_by
              WHERE a.id = ?",
            [$id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $applicationId): array
    {
        return $this->db->all(
            'SELECT id, doc_type, original_name, mime_type, size_bytes, status, created_at FROM seller_documents WHERE application_id = ? ORDER BY id',
            [$applicationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function history(int $applicationId): array
    {
        return $this->db->all(
            'SELECT h.*, u.first_name, u.last_name FROM seller_application_history h LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.application_id = ? ORDER BY h.id',
            [$applicationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $status, int $limit = 200): array
    {
        $where = $status !== null ? 'WHERE a.status = ?' : "WHERE a.status <> 'draft'";
        return $this->db->all(
            "SELECT a.id, a.application_number, a.store_name, a.business_type, a.status, a.submitted_at, a.reviewed_at, c.name AS county_name,
                    (SELECT COUNT(*) FROM seller_documents d WHERE d.application_id = a.id) AS documents
               FROM seller_applications a JOIN counties c ON c.id = a.county_id
              $where ORDER BY COALESCE(a.submitted_at, a.created_at) DESC LIMIT " . max(1, min(500, $limit)),
            $status !== null ? [$status] : []
        );
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        return array_map('intval', array_column($this->db->all('SELECT status, COUNT(*) AS n FROM seller_applications GROUP BY status'), 'n', 'status'));
    }

    /** @return list<array<string,mixed>> top-level categories applicants can choose from */
    public function categoryChoices(): array
    {
        return $this->db->all('SELECT id, name FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order, name');
    }

    // ------------------------------------------------------------------ applicant actions

    /**
     * Creates the applicant's draft or updates it. Only drafts are editable.
     * @param array<string,mixed> $in
     */
    public function saveDetails(int $userId, array $in): int
    {
        if ($this->db->value('SELECT 1 FROM seller_users WHERE user_id = ?', [$userId])) {
            throw ValidationException::withMessage('Your account already has a seller store.');
        }
        $data = $this->validateDetails($in);
        $app = $this->latestFor($userId);
        if ($app !== null && in_array($app['status'], ['submitted', 'under_review'], true)) {
            throw ValidationException::withMessage('Your application is being reviewed and can no longer be edited.');
        }
        if ($app !== null && $app['status'] === 'approved') {
            throw ValidationException::withMessage('Your application has already been approved.');
        }
        if ($app !== null && $app['status'] === 'rejected' && !(int) $app['resubmission_allowed']) {
            throw ValidationException::withMessage('This application cannot be resubmitted. Please contact ' . brand_name() . ' for details.');
        }
        if ($problem = $this->slugProblem($data['store_slug'], $app && $app['status'] === 'draft' ? (int) $app['id'] : null)) {
            throw new ValidationException(['store_slug' => $problem]);
        }

        if ($app !== null && $app['status'] === 'draft') {
            // Column names come from validateDetails() (fixed keys), never from the request.
            $set = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($data)));
            $this->db->exec("UPDATE seller_applications SET $set WHERE id = ? AND status = 'draft'", [...array_values($data), (int) $app['id']]);
            return (int) $app['id'];
        }
        return $this->db->transaction(function () use ($userId, $data, $app) {
            $id = $this->db->insert('seller_applications', $data + [
                'user_id' => $userId,
                'application_number' => $this->newNumber(),
                'status' => 'draft',
            ]);
            $this->record($id, null, 'draft', $userId, null);
            return $id;
        });
    }

    /** @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file */
    public function uploadDocument(int $userId, string $docType, array $file): int
    {
        $app = $this->ownDraft($userId);
        if (!in_array($docType, SellerDocumentPolicy::requested((string) $app['business_type']), true)) {
            throw new ValidationException(['doc_type' => 'Choose one of the listed document types.']);
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM seller_documents WHERE application_id = ?', [$app['id']]) >= self::MAX_DOCUMENTS) {
            throw new ValidationException(['document' => 'You can upload up to ' . self::MAX_DOCUMENTS . ' documents. Remove one first.']);
        }
        $stored = (new SellerDocumentStore())->store($file);
        try {
            return $this->db->insert('seller_documents', $stored + [
                'application_id' => (int) $app['id'], 'doc_type' => $docType, 'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            (new SellerDocumentStore())->delete($stored['stored_name']);
            throw $e;
        }
    }

    public function deleteDocument(int $userId, int $documentId): void
    {
        $app = $this->ownDraft($userId);
        $doc = $this->db->one('SELECT * FROM seller_documents WHERE id = ? AND application_id = ?', [$documentId, $app['id']])
            ?? throw \Waimart\Core\HttpException::notFound();
        $this->db->exec('DELETE FROM seller_documents WHERE id = ?', [$doc['id']]);
        (new SellerDocumentStore())->delete((string) $doc['stored_name']);
    }

    /**
     * The applicant's own document, for download. Null when it is not theirs (callers answer 404).
     * @return array<string,mixed>|null
     */
    public function documentForApplicant(int $userId, int $documentId): ?array
    {
        return $this->db->one(
            'SELECT d.* FROM seller_documents d JOIN seller_applications a ON a.id = d.application_id WHERE d.id = ? AND a.user_id = ?',
            [$documentId, $userId]
        );
    }

    /**
     * Payout details + terms + required documents, then draft → submitted.
     * @param array<string,mixed> $in
     */
    public function submit(int $userId, array $in): void
    {
        $app = $this->ownDraft($userId);
        $payout = $this->payout($in, $app);
        if (empty($in['accept_terms'])) {
            throw new ValidationException(['accept_terms' => 'You must accept the seller terms to submit your application.']);
        }
        $have = array_column($this->documents((int) $app['id']), 'doc_type');
        $missing = array_diff(SellerDocumentPolicy::required((string) $app['business_type']), $have);
        if ($missing) {
            $names = array_map(fn ($t) => SellerDocumentPolicy::TYPES[$t], $missing);
            throw new ValidationException(['document' => 'Please upload: ' . implode(', ', $names) . '.']);
        }
        if ($problem = $this->slugProblem((string) $app['store_slug'], (int) $app['id'])) {
            throw new ValidationException(['store_slug' => $problem . ' Edit your details to choose another store address.']);
        }

        $this->db->transaction(function () use ($app, $payout, $userId) {
            $this->transition((int) $app['id'], 'draft', 'submitted', $userId, null, $payout + [
                'terms_version' => (string) Settings::get('seller_terms_version', '1'),
                'terms_accepted_at' => date('Y-m-d H:i:s'),
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);
        });
        $fresh = (array) $this->find((int) $app['id']);
        $this->notifyApplicant($fresh, 'seller_application_submitted', 'We received your seller application ' . $fresh['application_number'],
            "Thank you for applying to sell on " . brand_name() . " as \"{$fresh['store_name']}\".\n\nYour application {$fresh['application_number']} is now waiting for review. "
            . "We will email you when a decision has been made. You can check its status at any time: " . config('app.url') . "/sell/application");
        if ($ops = (string) Settings::get('store_email', '')) {
            (new NotificationService())->email($ops, 'New seller application ' . $fresh['application_number'],
                "A new seller application is waiting for review: {$fresh['store_name']} ({$fresh['application_number']}).\n" . config('app.url') . '/admin/sellers/applications/' . $fresh['id'],
                'seller_application_staff');
        }
    }

    /** A rejected application whose resubmission is allowed becomes an editable draft again. */
    public function reopen(int $userId): void
    {
        $app = $this->latestFor($userId);
        if ($app === null || $app['status'] !== 'rejected' || !(int) $app['resubmission_allowed']) {
            throw ValidationException::withMessage('There is no application that can be resubmitted.');
        }
        $this->db->transaction(fn () => $this->transition((int) $app['id'], 'rejected', 'draft', $userId, 'Reopened by applicant for resubmission'));
    }

    // ------------------------------------------------------------------ staff actions

    public function startReview(int $applicationId, int $staffId): void
    {
        $this->db->transaction(function () use ($applicationId, $staffId) {
            $app = $this->lock($applicationId);
            $this->assertNotOwn($app, $staffId);
            $this->transition($applicationId, 'submitted', 'under_review', $staffId, null, ['reviewed_by' => $staffId]);
        });
        AuditLogger::log('seller_application.review_started', 'seller_application', $applicationId, null, null, $staffId);
    }

    /**
     * Approves: creates the seller store, makes the applicant its owner, carries over payout details and documents.
     * Staff may correct the store name/address at approval.
     * @param array<string,mixed> $in
     */
    public function approve(int $applicationId, int $staffId, array $in = []): int
    {
        $sellerId = $this->db->transaction(function () use ($applicationId, $staffId, $in) {
            $app = $this->lock($applicationId);
            $this->assertNotOwn($app, $staffId);
            if (!in_array($app['status'], ['submitted', 'under_review'], true)) {
                throw ValidationException::withMessage('Only submitted applications can be approved.');
            }
            $name = trim((string) ($in['store_name'] ?? '')) ?: (string) $app['store_name'];
            $slug = self::normalizeSlug((string) ($in['store_slug'] ?? '') ?: (string) $app['store_slug']);
            Validator::validate(['store_name' => $name, 'store_slug' => $slug], ['store_name' => 'required|min:3|max:120', 'store_slug' => 'required|min:3|max:60|slug'],
                ['store_name' => 'Store name', 'store_slug' => 'Store address']);
            if ($this->db->value('SELECT 1 FROM sellers WHERE name = ?', [$name])) {
                throw new ValidationException(['store_name' => 'Another store already uses this name.']);
            }
            if ($problem = $this->slugProblem($slug, $applicationId, true)) {
                throw new ValidationException(['store_slug' => $problem]);
            }
            if ($this->db->value('SELECT 1 FROM seller_users WHERE user_id = ?', [$app['user_id']])) {
                throw ValidationException::withMessage("The applicant's account already belongs to a store.");
            }
            $sellerId = $this->db->insert('sellers', [
                'name' => $name, 'slug' => $slug, 'legal_name' => $app['legal_name'], 'business_type' => $app['business_type'],
                'description' => $app['business_description'], 'public_email' => $app['email'], 'public_phone' => $app['phone'],
                'county_id' => $app['county_id'], 'town' => $app['town'], 'status' => 'active',
                'approved_at' => date('Y-m-d H:i:s'), 'approved_by' => $staffId,
            ]);
            $this->db->insert('seller_users', ['seller_id' => $sellerId, 'user_id' => $app['user_id'], 'role' => 'owner', 'created_by' => $staffId]);
            if ($app['payout_details_enc']) {
                $this->db->insert('seller_payout_methods', [
                    'seller_id' => $sellerId, 'method' => $app['payout_method'], 'details_enc' => $app['payout_details_enc'],
                    'masked' => $app['payout_masked'], 'created_by' => (int) $app['user_id'],
                ]);
            }
            $this->db->exec('UPDATE seller_documents SET seller_id = ? WHERE application_id = ?', [$sellerId, $applicationId]);
            $this->transition($applicationId, (string) $app['status'], 'approved', $staffId, trim((string) ($in['note'] ?? '')) ?: null, [
                'seller_id' => $sellerId, 'reviewed_by' => $staffId, 'reviewed_at' => date('Y-m-d H:i:s'), 'decision_reason' => null,
            ]);
            AuditLogger::log('seller.approved', 'seller', $sellerId, null, ['application' => $app['application_number'], 'name' => $name, 'slug' => $slug], $staffId);
            return $sellerId;
        });
        $app = (array) $this->find($applicationId);
        $this->notifyApplicant($app, 'seller_application_approved', 'Welcome to ' . brand_name() . ' — your seller application is approved',
            "Good news! Your application {$app['application_number']} has been approved and your store is ready.\n\n"
            . "Sign in to the Seller Centre to complete your store profile: " . config('app.url') . "/seller");
        return $sellerId;
    }

    public function reject(int $applicationId, int $staffId, string $reason, bool $resubmissionAllowed): void
    {
        $reason = trim($reason);
        Validator::validate(['reason' => $reason], ['reason' => 'required|min:5|max:1000'], ['reason' => 'Reason']);
        $this->db->transaction(function () use ($applicationId, $staffId, $reason, $resubmissionAllowed) {
            $app = $this->lock($applicationId);
            $this->assertNotOwn($app, $staffId);
            if (!in_array($app['status'], ['submitted', 'under_review'], true)) {
                throw ValidationException::withMessage('Only submitted applications can be rejected.');
            }
            $this->transition($applicationId, (string) $app['status'], 'rejected', $staffId, $reason, [
                'reviewed_by' => $staffId, 'reviewed_at' => date('Y-m-d H:i:s'),
                'decision_reason' => $reason, 'resubmission_allowed' => $resubmissionAllowed ? 1 : 0,
            ]);
        });
        AuditLogger::log('seller_application.rejected', 'seller_application', $applicationId, null,
            ['reason' => $reason, 'resubmission_allowed' => $resubmissionAllowed], $staffId);
        $app = (array) $this->find($applicationId);
        $this->notifyApplicant($app, 'seller_application_rejected', 'Update on your seller application ' . $app['application_number'],
            "We are unable to approve your seller application {$app['application_number']} at this time.\n\nReason: $reason\n\n"
            . ($resubmissionAllowed
                ? 'You may update your application and submit it again: ' . config('app.url') . '/sell/application'
                : 'This application cannot be resubmitted. Please contact ' . brand_name() . ' if you have questions.'));
    }

    // ------------------------------------------------------------------ helpers

    public static function normalizeSlug(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        return mb_substr(trim($s, '-'), 0, 60);
    }

    /** Why a store address cannot be used, or null if it is free. */
    public function slugProblem(string $slug, ?int $exceptApplicationId = null, bool $approving = false): ?string
    {
        if (mb_strlen($slug) < 3) {
            return 'The store address must have at least 3 letters or numbers.';
        }
        $compact = str_replace('-', '', $slug);
        if (in_array($slug, self::RESERVED_SLUGS, true) || array_filter(Brand::protectedNames(), fn ($n) => str_contains($compact, $n))) {
            return 'This store address is reserved. Please choose another.';
        }
        if ($this->db->value('SELECT 1 FROM sellers WHERE slug = ?', [$slug])) {
            return 'This store address is already taken.';
        }
        $other = $this->db->value(
            "SELECT 1 FROM seller_applications WHERE store_slug = ? AND status IN ('submitted','under_review') AND id <> ?",
            [$slug, $exceptApplicationId ?? 0]
        );
        return $other && !$approving ? 'Another applicant has already requested this store address.' : null;
    }

    /** @return array<string,mixed> */
    private function validateDetails(array $in): array
    {
        $rules = [
            'store_name' => 'required|min:3|max:120', 'store_slug' => 'nullable|max:60', 'contact_name' => 'required|max:160',
            'email' => 'required|email', 'phone' => 'required|phone', 'business_type' => 'required|in:individual,registered_business',
            'legal_name' => 'nullable|max:160', 'business_description' => 'required|min:30|max:2000', 'county_id' => 'required|int',
            'town' => 'required|max:100', 'business_address' => 'nullable|max:255', 'registration_number' => 'nullable|max:60',
        ];
        $labels = ['store_slug' => 'Store address', 'business_description' => 'Business description', 'county_id' => 'County',
                   'legal_name' => 'Registered business name'];
        $errors = Validator::check($in, $rules, $labels);
        $name = trim((string) ($in['store_name'] ?? ''));
        $compactName = str_replace([' ', '-', '.', '_'], '', strtolower($name));
        if (!isset($errors['store_name']) && array_filter(Brand::protectedNames(), fn ($n) => str_contains($compactName, $n))) {
            $errors['store_name'] = 'Store names may not include "' . brand_name() . '" or look like it.';
        }
        if (($in['business_type'] ?? '') === 'registered_business' && trim((string) ($in['legal_name'] ?? '')) === '') {
            $errors['legal_name'] = 'Enter the registered business name.';
        }
        if (!isset($errors['county_id']) && !$this->db->value('SELECT 1 FROM counties WHERE id = ?', [(int) $in['county_id']])) {
            $errors['county_id'] = 'Select a valid county.';
        }
        $valid = array_map('intval', array_column($this->categoryChoices(), 'id'));
        $cats = array_values(array_unique(array_filter(array_map('intval', (array) ($in['category_ids'] ?? [])))));
        if (!$cats || array_diff($cats, $valid)) {
            $errors['category_ids'] = 'Choose at least one product category you plan to sell in.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $slug = self::normalizeSlug((string) ($in['store_slug'] ?? '') ?: $name);
        $isBusiness = $in['business_type'] === 'registered_business';
        return [
            'store_name' => $name,
            'store_slug' => $slug,
            'contact_name' => trim((string) $in['contact_name']),
            'email' => strtolower(trim((string) $in['email'])),
            'phone' => (string) Validator::normalizePhone((string) $in['phone']),
            'business_type' => (string) $in['business_type'],
            'legal_name' => $isBusiness ? trim((string) $in['legal_name']) : (trim((string) ($in['legal_name'] ?? '')) ?: null),
            'business_description' => trim((string) $in['business_description']),
            'county_id' => (int) $in['county_id'],
            'town' => trim((string) $in['town']),
            'business_address' => trim((string) ($in['business_address'] ?? '')) ?: null,
            'registration_number' => trim((string) ($in['registration_number'] ?? '')) ?: null,
            'category_ids' => implode(',', $cats),
        ];
    }

    /**
     * Validates payout details and returns encrypted columns. Blank fields keep previously saved details.
     * @return array<string,mixed>
     */
    private function payout(array $in, array $app): array
    {
        $method = (string) ($in['payout_method'] ?? '');
        $fields = ['payout_phone', 'payout_name', 'bank_name', 'bank_branch', 'account_name', 'account_number'];
        $anyGiven = array_filter($fields, fn ($f) => trim((string) ($in[$f] ?? '')) !== '');
        if (!$anyGiven && $app['payout_details_enc'] && ($method === '' || $method === $app['payout_method'])) {
            return [];
        }
        if ($method === 'mpesa') {
            Validator::validate($in, ['payout_phone' => 'required|phone', 'payout_name' => 'required|max:120'],
                ['payout_phone' => 'M-Pesa number', 'payout_name' => 'Registered M-Pesa name']);
            $phone = (string) Validator::normalizePhone((string) $in['payout_phone']);
            $details = ['phone' => $phone, 'name' => trim((string) $in['payout_name'])];
            $masked = 'M-Pesa 0' . substr($phone, 3, 1) . '•• ••• ' . substr($phone, -3);
        } elseif ($method === 'bank') {
            Validator::validate($in, ['bank_name' => 'required|max:80', 'bank_branch' => 'nullable|max:80', 'account_name' => 'required|max:120',
                'account_number' => 'required|min:6|max:20'], ['account_number' => 'Account number']);
            $number = preg_replace('/\s+/', '', (string) $in['account_number']);
            if (!preg_match('/^\d{6,20}$/', (string) $number)) {
                throw new ValidationException(['account_number' => 'Enter the account number using digits only.']);
            }
            $details = ['bank' => trim((string) $in['bank_name']), 'branch' => trim((string) ($in['bank_branch'] ?? '')),
                        'account_name' => trim((string) $in['account_name']), 'account_number' => $number];
            $masked = mb_substr($details['bank'], 0, 40) . ' ••••' . substr((string) $number, -4);
        } else {
            throw new ValidationException(['payout_method' => 'Choose how you would like to receive payouts.']);
        }
        return ['payout_method' => $method, 'payout_details_enc' => Crypto::encrypt((string) json_encode($details)), 'payout_masked' => $masked];
    }

    /** @return array<string,mixed> the user's draft application, or a validation error */
    private function ownDraft(int $userId): array
    {
        $app = $this->latestFor($userId);
        if ($app === null || $app['status'] !== 'draft') {
            throw ValidationException::withMessage('Only a draft application can be changed.');
        }
        return $app;
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM seller_applications WHERE id = ? FOR UPDATE', [$id])
            ?? throw \Waimart\Core\HttpException::notFound();
    }

    /** Staff may not review their own application. */
    private function assertNotOwn(array $app, int $staffId): void
    {
        if ((int) $app['user_id'] === $staffId) {
            throw \Waimart\Core\HttpException::forbidden('You cannot review your own seller application.');
        }
    }

    /** Guarded status change + history row. @param array<string,mixed> $sets */
    private function transition(int $id, string $from, string $to, ?int $actorId, ?string $note, array $sets = []): void
    {
        $cols = ['status = ?'];
        $params = [$to];
        foreach ($sets as $k => $v) {
            $cols[] = "`$k` = ?";
            $params[] = $v;
        }
        $params[] = $id;
        $params[] = $from;
        if ($this->db->exec('UPDATE seller_applications SET ' . implode(', ', $cols) . ' WHERE id = ? AND status = ?', $params) !== 1) {
            throw ValidationException::withMessage('This application changed in the meantime. Please reload and try again.');
        }
        $this->record($id, $from, $to, $actorId, $note);
    }

    private function record(int $id, ?string $from, string $to, ?int $actorId, ?string $note): bool
    {
        $this->db->insert('seller_application_history', [
            'application_id' => $id, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actorId,
            'note' => $note !== null ? mb_substr($note, 0, 1000) : null,
        ]);
        return true;
    }

    private function newNumber(): string
    {
        do {
            $n = 'SA-' . Crypto::code(8);
        } while ($this->db->value('SELECT 1 FROM seller_applications WHERE application_number = ?', [$n]));
        return $n;
    }

    private function notifyApplicant(array $app, string $template, string $subject, string $body): void
    {
        (new NotificationService())->sendTemplate('seller_message', ['first_name' => (string) $app['contact_name'], 'subject' => $subject, 'message' => $body] + MessageTemplates::contactVars(),
            ['email' => (string) $app['email']], ['user_id' => (int) $app['user_id'], 'log_as' => $template]);
    }
}
