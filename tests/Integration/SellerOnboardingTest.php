<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\HttpException;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\SellerApplicationService;
use Waimart\Services\SellerContext;
use Waimart\Services\SellerDocumentPolicy;
use Waimart\Services\SellerDocumentStore;
use Waimart\Services\SellerService;
use Waimart\Services\Sellers;

/** Phase 3: seller applications, documents, review, approval, suspension (service level). */
final class SellerOnboardingTest extends DbTestCase
{
    private SellerApplicationService $apps;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->apps = new SellerApplicationService($this->db);
        Settings::set('seller_required_documents', '{"individual":["national_id"],"registered_business":["national_id","business_registration"],"optional":["other"]}');
    }

    protected function tearDown(): void
    {
        foreach ($this->db->all('SELECT stored_name FROM seller_documents') as $d) {
            (new SellerDocumentStore())->delete((string) $d['stored_name']);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        Settings::set('seller_required_documents', '{"individual":["national_id"],"registered_business":["national_id"],"optional":["business_registration"]}');
        parent::tearDown();
    }

    private function user(string $email = 'seller@example.com', bool $staff = false): int
    {
        return $this->db->insert('users', ['email' => $email, 'password_hash' => 'x', 'first_name' => 'Amina', 'last_name' => 'Otieno', 'is_staff' => (int) $staff]);
    }

    /** @return array<string,mixed> */
    private function details(array $over = []): array
    {
        $cat = (int) ($this->db->value('SELECT id FROM categories WHERE parent_id IS NULL LIMIT 1')
            ?: $this->db->insert('categories', ['name' => 'Fashion', 'slug' => 'fashion-' . bin2hex(random_bytes(2))]));
        return $over + [
            'store_name' => 'Amina Crafts', 'store_slug' => '', 'contact_name' => 'Amina Otieno', 'email' => 'amina@example.com',
            'phone' => '0712 345 678', 'business_type' => 'individual', 'business_description' => 'Handmade kiondo bags and beadwork from Kisumu since 2019.',
            'county_id' => '42', 'town' => 'Kisumu', 'category_ids' => [(string) $cat],
        ];
    }

    private function file(string $content, string $name = 'id.pdf'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wmdoc');
        file_put_contents($path, $content);
        $this->tmp[] = $path;
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function pdf(): array
    {
        return $this->file("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function png(): array
    {
        $im = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($im);
        return $this->file((string) ob_get_clean(), 'id.png');
    }

    private function submitted(int $user, array $over = []): int
    {
        $id = $this->apps->saveDetails($user, $this->details($over));
        $this->apps->uploadDocument($user, 'national_id', $this->pdf());
        if (($over['business_type'] ?? '') === 'registered_business') {
            $this->apps->uploadDocument($user, 'business_registration', $this->png());
        }
        $this->apps->submit($user, ['payout_method' => 'mpesa', 'payout_phone' => '0722000111', 'payout_name' => 'Amina Otieno', 'accept_terms' => '1']);
        return $id;
    }

    public function testFullLifecycleCreatesActiveStoreOwnedByApplicant(): void
    {
        $applicant = $this->user();
        $staff = $this->user('ops@waimart.test', true);
        $id = $this->submitted($applicant);
        $app = (array) $this->apps->find($id);
        $this->assertSame('submitted', $app['status']);
        $this->assertSame('amina-crafts', $app['store_slug'], 'slug derived from the store name');
        $this->assertSame('254712345678', $app['phone']);
        $this->assertNotNull($app['terms_accepted_at']);

        $this->apps->startReview($id, $staff);
        $sellerId = $this->apps->approve($id, $staff);

        $seller = (array) (new SellerService($this->db))->find($sellerId);
        $this->assertSame(['Amina Crafts', 'amina-crafts', 'active', '0'], [$seller['name'], $seller['slug'], $seller['status'], (string) $seller['is_platform']]);
        $this->assertSame('owner', $this->db->value('SELECT role FROM seller_users WHERE seller_id = ? AND user_id = ?', [$sellerId, $applicant]));
        $this->assertSame(0, (int) $this->db->value('SELECT is_staff FROM users WHERE id = ?', [$applicant]), 'approval never grants staff rights');
        $this->assertSame($sellerId, (int) $this->db->value('SELECT seller_id FROM seller_documents WHERE application_id = ?', [$id]));
        $method = (array) $this->db->one('SELECT * FROM seller_payout_methods WHERE seller_id = ?', [$sellerId]);
        $this->assertSame(['phone' => '254722000111', 'name' => 'Amina Otieno'], json_decode((string) Crypto::decrypt($method['details_enc']), true));
        $this->assertStringNotContainsString('722000111', $method['masked']);
        $this->assertSame(['draft', 'submitted', 'under_review', 'approved'], array_column($this->apps->history($id), 'to_status'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'seller.approved'"));
        $this->assertSame(['seller_application_submitted', 'seller_application_approved'],
            array_column($this->db->all("SELECT template FROM notifications WHERE template LIKE 'seller_application_%' AND user_id = ? ORDER BY id", [$applicant]), 'template'));
    }

    public function testPayoutDetailsAreNeverStoredInClear(): void
    {
        $u = $this->user();
        $id = $this->submitted($u);
        $row = json_encode($this->db->one('SELECT * FROM seller_applications WHERE id = ?', [$id]));
        $this->assertStringNotContainsString('722000111', (string) $row);
        $this->assertStringNotContainsString('722000111', (string) json_encode($this->db->all('SELECT * FROM audit_logs')));
    }

    public function testSubmissionNeedsRequiredDocumentsPayoutAndTerms(): void
    {
        $u = $this->user();
        $this->apps->saveDetails($u, $this->details(['business_type' => 'registered_business', 'legal_name' => 'Amina Crafts Ltd']));
        $payout = ['payout_method' => 'bank', 'bank_name' => 'KCB', 'account_name' => 'Amina Crafts Ltd', 'account_number' => '1234567890', 'accept_terms' => '1'];
        $this->assertValidation(fn () => $this->apps->submit($u, $payout), 'document');
        $this->apps->uploadDocument($u, 'national_id', $this->pdf());
        $this->assertValidation(fn () => $this->apps->submit($u, $payout), 'document', 'business registration still missing');
        $this->apps->uploadDocument($u, 'business_registration', $this->pdf());
        $this->assertValidation(fn () => $this->apps->submit($u, ['accept_terms' => '1']), 'payout_method');
        $this->assertValidation(fn () => $this->apps->submit($u, ['account_number' => 'abc123'] + $payout), 'account_number');
        $this->assertValidation(fn () => $this->apps->submit($u, ['accept_terms' => ''] + $payout), 'accept_terms');
        $this->apps->submit($u, $payout);
        $this->assertSame('KCB ••••7890', $this->db->value('SELECT payout_masked FROM seller_applications WHERE user_id = ?', [$u]));
    }

    public function testDetailsValidationAndStoreNameRules(): void
    {
        $u = $this->user();
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['store_name' => 'Waimart Deals'])), 'store_name');
        // No store may pass itself off as the platform under its current name (from settings), however it is spelled.
        foreach (['Basemall Deals', 'Base Mall Store', 'BASE-MALL Outlet'] as $impostor) {
            $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['store_name' => $impostor])), 'store_name');
        }
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['store_slug' => 'basemall-deals'])), 'store_slug');
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['business_description' => 'Too short'])), 'business_description');
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['category_ids' => ['999999']])), 'category_ids');
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['county_id' => '99'])), 'county_id');
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['business_type' => 'registered_business'])), 'legal_name');
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['phone' => '12345'])), 'phone');
    }

    public function testStoreAddressesAreNormalisedReservedAndUnique(): void
    {
        $this->assertSame('mama-mboga-s-shop', SellerApplicationService::normalizeSlug("  Mama Mboga's Shop!! "));
        $u = $this->user();
        foreach (['admin', 'waimart-official', 'waimartdeals', 'ab'] as $bad) {
            $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['store_slug' => $bad])), 'store_slug', $bad);
        }
        $this->db->insert('sellers', ['name' => 'Taken Store', 'slug' => 'taken-store']);
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['store_slug' => 'taken-store'])), 'store_slug');

        $other = $this->user('other@example.com');
        $this->submitted($other, ['store_slug' => 'kiondo-house', 'store_name' => 'Kiondo House']);
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details(['store_slug' => 'kiondo-house'])), 'store_slug', 'requested by another applicant');
    }

    public function testOnlyDraftsAreEditableAndResubmissionRules(): void
    {
        $u = $this->user();
        $staff = $this->user('ops@waimart.test', true);
        $id = $this->submitted($u);
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details()), '_form', 'no edits after submission');
        $this->assertValidation(fn () => $this->apps->uploadDocument($u, 'national_id', $this->pdf()), '_form');

        $this->apps->reject($id, $staff, 'Photo of ID is unreadable', true);
        $this->assertSame('Photo of ID is unreadable', $this->db->value('SELECT decision_reason FROM seller_applications WHERE id = ?', [$id]));
        $this->apps->reopen($u);
        $this->assertSame('draft', $this->db->value('SELECT status FROM seller_applications WHERE id = ?', [$id]));
        $this->apps->saveDetails($u, $this->details(['town' => 'Kisumu CBD']));
        $this->apps->submit($u, ['accept_terms' => '1']);
        $this->assertSame('submitted', $this->db->value('SELECT status FROM seller_applications WHERE id = ?', [$id]), 'saved payout details are kept');

        $this->apps->reject($id, $staff, 'Prohibited products', false);
        $this->assertValidation(fn () => $this->apps->reopen($u), '_form');
        $this->assertValidation(fn () => $this->apps->saveDetails($u, $this->details()), '_form', 'no new application either');
    }

    public function testStaffCannotReviewTheirOwnApplicationAndDecisionsAreGuarded(): void
    {
        $staff = $this->user('ops@waimart.test', true);
        $id = $this->submitted($staff);
        foreach ([fn () => $this->apps->startReview($id, $staff), fn () => $this->apps->approve($id, $staff), fn () => $this->apps->reject($id, $staff, 'Self review', true)] as $call) {
            try {
                $call();
                $this->fail('self-review must be refused');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $other = $this->user('mgr@waimart.test', true);
        $this->apps->approve($id, $other);
        $this->assertValidation(fn () => $this->apps->approve($id, $other), '_form', 'cannot approve twice');
        $this->assertValidation(fn () => $this->apps->reject($id, $other, 'Too late now', true), '_form');
    }

    public function testApprovalRechecksNameAndAddressAndAllowsStaffCorrection(): void
    {
        $u = $this->user();
        $staff = $this->user('ops@waimart.test', true);
        $id = $this->submitted($u);
        $this->db->insert('sellers', ['name' => 'Amina Crafts', 'slug' => 'someone-else']);   // name taken meanwhile
        $this->assertValidation(fn () => $this->apps->approve($id, $staff), 'store_name');
        $this->assertValidation(fn () => $this->apps->approve($id, $staff, ['store_name' => 'Amina Crafts KE', 'store_slug' => 'admin']), 'store_slug');
        $sellerId = $this->apps->approve($id, $staff, ['store_name' => 'Amina Crafts KE', 'store_slug' => 'amina-crafts-ke']);
        $this->assertSame('amina-crafts-ke', $this->db->value('SELECT slug FROM sellers WHERE id = ?', [$sellerId]));
    }

    public function testDocumentUploadsAreValidatedAndStoredPrivately(): void
    {
        $u = $this->user();
        $this->apps->saveDetails($u, $this->details());
        $this->assertValidation(fn () => $this->apps->uploadDocument($u, 'national_id', $this->file("<?php system(\$_GET['c']);", 'id.pdf')), 'document', 'PHP disguised as PDF');
        $this->assertValidation(fn () => $this->apps->uploadDocument($u, 'national_id', $this->file('%PDF-1.4 <?php echo 1; ?>', 'x.pdf')), 'document', 'PHP inside a PDF header');
        $this->assertValidation(fn () => $this->apps->uploadDocument($u, 'national_id', $this->file('just text', 'id.txt')), 'document');
        $this->assertValidation(fn () => $this->apps->uploadDocument($u, 'national_id', $this->file('%PDF-' . str_repeat('a', SellerDocumentStore::MAX_BYTES))), 'document', 'over 5 MB');
        $this->assertValidation(fn () => $this->apps->uploadDocument($u, 'bank_statement', $this->pdf()), 'doc_type', 'unknown type');

        $docId = $this->apps->uploadDocument($u, 'national_id', $this->file("%PDF-1.4\n%%EOF", '../../evil name.pdf'));
        $doc = (array) $this->db->one('SELECT * FROM seller_documents WHERE id = ?', [$docId]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $doc['stored_name']);
        $this->assertSame('evil_name.pdf', $doc['original_name'], 'display name sanitised');
        $path = (new SellerDocumentStore())->path($doc['stored_name']);
        $this->assertFileExists($path);
        $this->assertStringContainsString(str_replace('\\', '/', \Waimart\Core\App::root()) . '/storage/private/', str_replace('\\', '/', $path));
        $this->assertStringNotContainsString('/public/', str_replace('\\', '/', $path));
        $this->expectException(\InvalidArgumentException::class);
        (new SellerDocumentStore())->path('../../config/config.local.php');
    }

    public function testApplicantsCanOnlyReachTheirOwnDocuments(): void
    {
        $a = $this->user('a@example.com');
        $b = $this->user('b@example.com');
        $this->apps->saveDetails($a, $this->details());
        $doc = $this->apps->uploadDocument($a, 'national_id', $this->pdf());
        $this->assertNotNull($this->apps->documentForApplicant($a, $doc));
        $this->assertNull($this->apps->documentForApplicant($b, $doc));
        $this->apps->saveDetails($b, $this->details(['store_name' => 'Other Shop', 'email' => 'b@example.com']));
        try {
            $this->apps->deleteDocument($b, $doc);
            $this->fail("deleting someone else's document must fail");
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM seller_documents WHERE id = ?', [$doc]));
    }

    public function testSuspensionAndReactivationKeepRecordsAndNotify(): void
    {
        $u = $this->user();
        $staff = $this->user('ops@waimart.test', true);
        $sellerId = $this->apps->approve($this->submitted($u), $staff);
        $svc = new SellerService($this->db);
        $this->assertValidation(fn () => $svc->suspend($sellerId, $staff, 'no'), 'reason');
        $svc->suspend($sellerId, $staff, 'Repeated late dispatch');
        $this->assertSame('suspended', $this->db->value('SELECT status FROM sellers WHERE id = ?', [$sellerId]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM seller_users WHERE seller_id = ?', [$sellerId]), 'membership kept');
        $this->assertValidation(fn () => $svc->suspend($sellerId, $staff, 'Again please'), '_form');
        $svc->reactivate($sellerId, $staff);
        $this->assertSame('active', $this->db->value('SELECT status FROM sellers WHERE id = ?', [$sellerId]));
        $this->assertSame(['seller.approved', 'seller.suspended', 'seller.reactivated'],
            array_column($this->db->all("SELECT action FROM audit_logs WHERE entity_type = 'seller' ORDER BY id"), 'action'));
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template IN ('seller_suspended','seller_reactivated')"));
        $this->assertValidation(fn () => $svc->suspend(Sellers::platformId($this->db), $staff, 'Not allowed here'), '_form', 'platform store is protected');
    }

    public function testSellerContextComesFromMembershipOnly(): void
    {
        $u = $this->user();
        $staff = $this->user('ops@waimart.test', true);
        $sellerId = $this->apps->approve($this->submitted($u), $staff);
        $_SESSION['user_id'] = $u;
        $_SESSION['auth_at'] = time() + 5;
        \Waimart\Core\Auth::reset();
        $this->assertSame($sellerId, SellerContext::id());
        $this->assertSame('owner', SellerContext::role());
        $this->assertTrue(SellerContext::isActive());
        $_SESSION['seller_id'] = 999;   // nothing reads session/request values for the seller
        \Waimart\Core\Auth::reset();
        $this->assertSame($sellerId, SellerContext::id());
        unset($_SESSION['user_id'], $_SESSION['auth_at'], $_SESSION['seller_id']);
        \Waimart\Core\Auth::reset();
    }

    public function testDocumentPolicyRoundTrip(): void
    {
        $json = SellerDocumentPolicy::encode(['national_id' => 'all', 'business_registration' => 'business', 'tax_registration' => 'optional', 'other' => 'off']);
        Settings::set('seller_required_documents', $json);
        $this->assertSame(['national_id'], SellerDocumentPolicy::required('individual'));
        $this->assertSame(['national_id', 'business_registration'], SellerDocumentPolicy::required('registered_business'));
        $this->assertSame(['national_id', 'tax_registration'], SellerDocumentPolicy::requested('individual'));
        $this->assertSame('business', SellerDocumentPolicy::modes()['business_registration']);
        $this->expectException(ValidationException::class);
        SellerDocumentPolicy::encode(['national_id' => 'mandatory-by-law']);
    }

    private function assertValidation(callable $fn, string $field, string $why = ''): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors, "expected a '$field' error $why: " . json_encode($e->errors));
            return;
        }
        $this->fail("Expected a validation error on '$field' $why");
    }
}
