<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\ValidationException;
use Waimart\Services\CollectionService;
use Waimart\Services\OrderWorkflow;

/** Pickup workflow (B4): ready ≠ collected; codes are secret, rate-limited, single-use. */
final class CollectionWorkflowTest extends DbTestCase
{
    use PlacesOrders;

    private OrderWorkflow $wf;
    private CollectionService $collect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        $this->wf = new OrderWorkflow($this->db);
        $this->collect = new CollectionService($this->db);
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    private function staff(): array
    {
        return ['type' => 'staff', 'id' => $this->staffUser()];
    }

    /** Places a COD pickup order and moves it to "processing". */
    private function pickupOrder(int $variantId, int $qty = 1): array
    {
        $o = $this->placeOrder([[$variantId, $qty]], 'cod', 'pickup');
        $this->wf->transition((int) $o['id'], 'confirmed', $this->staff());
        $this->wf->transition((int) $o['id'], 'processing', $this->staff());
        return $o;
    }

    private function readyWithCode(array $order): string
    {
        $this->collect->markReady((int) $order['id'], $this->staffUser());
        $code = CollectionService::codeFor((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$order['id']]));
        $this->assertNotNull($code);
        return (string) $code;
    }

    public function testReadyGeneratesSecretCodeAndNotifiesWithoutStoringIt(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->pickupOrder($v);
        $code = $this->readyWithCode($o);

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
        $f = $this->db->one('SELECT * FROM order_fulfilment WHERE order_id = ?', [$o['id']]);
        $this->assertSame('active', $f['collection_code_status']);
        $this->assertSame(Crypto::hash($code), $f['collection_code_hash']);
        $this->assertStringNotContainsString($code, (string) $f['collection_code_enc'], 'encrypted, not plain');
        $this->assertNotNull($f['ready_at']);
        $this->assertSame($this->staffUser(), (int) $f['ready_by']);

        $bodies = (string) $this->db->value("SELECT GROUP_CONCAT(body SEPARATOR '|') FROM notifications WHERE order_id = ? AND template = 'order_ready_for_collection'", [$o['id']]);
        $this->assertStringContainsString('Collection code: [redacted]', $bodies, 'code redacted in the stored outbox');
        $this->assertStringNotContainsString($code, $bodies);
        $this->assertStringContainsString($code, (string) file_get_contents(App::root() . '/storage/logs/mail-' . date('Y-m-d') . '.log'), 'but delivered to the customer');
        $this->assertSame(1, $this->levels($v)['reserved'], 'ready does not consume stock');
    }

    public function testReadyIsNeverCollectedWithoutVerifiedCode(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->pickupOrder($v);
        $this->readyWithCode($o);
        try {
            $this->wf->transition((int) $o['id'], 'collected', $this->staff());
            $this->fail('Plain transition to collected must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('collection code', $e->getMessage());
        }
        $this->assertSame('ready_for_collection', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
    }

    public function testCorrectCodeCollectsAndConsumesOnce(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->pickupOrder($v, 2);
        $code = $this->readyWithCode($o);

        $done = $this->collect->verifyAndCollect((int) $o['id'], strtolower(substr($code, 0, 4) . ' ' . substr($code, 4)), $this->staffUser(), true, 'Collected by brother');
        $this->assertSame('collected', $done['status']);
        $this->assertSame(['on_hand' => 3, 'reserved' => 0], $this->levels($v), 'stock deducted at collection');
        $f = $this->db->one('SELECT * FROM order_fulfilment WHERE order_id = ?', [$o['id']]);
        $this->assertSame('used', $f['collection_code_status']);
        $this->assertSame($this->staffUser(), (int) $f['collected_by']);
        $this->assertStringContainsString('Collected by brother', (string) $f['collection_outcome']);
        $this->assertNull(CollectionService::codeFor($done), 'code no longer shown');

        // The same code cannot be used again.
        $this->expectException(ValidationException::class);
        $this->collect->verifyAndCollect((int) $o['id'], $code, $this->staffUser(), true);
    }

    public function testIdCheckIsMandatory(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->pickupOrder($v);
        $code = $this->readyWithCode($o);
        try {
            $this->collect->verifyAndCollect((int) $o['id'], $code, $this->staffUser(), false);
            $this->fail('ID check required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id_checked', $e->errors);
        }
        $this->assertSame('ready_for_collection', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
    }

    public function testWrongCodesAreAuditedAndRateLimited(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->pickupOrder($v);
        $code = $this->readyWithCode($o);
        for ($i = 0; $i < CollectionService::MAX_ATTEMPTS_PER_ORDER; $i++) {
            try {
                $this->collect->verifyAndCollect((int) $o['id'], 'WRONG' . $i . 'XX', $this->staffUser(), true);
                $this->fail('wrong code accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('code', $e->errors);
            }
        }
        $this->assertSame(5, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'collection.code_failed' AND entity_id = ?", [$o['id']]));
        // Locked: even the right code is refused until the window passes or the code is re-issued.
        try {
            $this->collect->verifyAndCollect((int) $o['id'], $code, $this->staffUser(), true);
            $this->fail('should be rate-limited');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many incorrect codes', $e->getMessage());
        }
        $this->assertSame(1, $this->levels($v)['reserved']);

        // A manager re-issues: the old code stops working, the new one works.
        $this->collect->reissueCode((int) $o['id'], $this->staffUser());
        $new = (string) CollectionService::codeFor((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]));
        $this->assertNotSame($code, $new);
        try {
            $this->collect->verifyAndCollect((int) $o['id'], $code, $this->staffUser(), true);
            $this->fail('old code must fail');
        } catch (ValidationException) {
        }
        $this->assertSame('collected', $this->collect->verifyAndCollect((int) $o['id'], $new, $this->staffUser(), true)['status']);
    }

    public function testCancellingReadyOrderInvalidatesCodeAndReleasesStock(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->pickupOrder($v, 2);
        $code = $this->readyWithCode($o);
        $this->wf->transition((int) $o['id'], 'cancelled', $this->staff(), null, ['reason' => 'Not collected within hold period']);
        $this->assertSame('invalidated', $this->db->value('SELECT collection_code_status FROM order_fulfilment WHERE order_id = ?', [$o['id']]));
        $this->assertSame(['on_hand' => 5, 'reserved' => 0], $this->levels($v));
        $this->expectException(ValidationException::class);
        $this->collect->verifyAndCollect((int) $o['id'], $code, $this->staffUser(), true);
    }

    public function testReadyOnlyForPickupOrdersAfterProcessing(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $early = $this->placeOrder([[$v, 1]], 'cod', 'pickup');
        $delivery = $this->placeOrder([[$v, 1]], 'cod', 'delivery');
        foreach ([$early, $delivery] as $o) {
            try {
                $this->collect->markReady((int) $o['id'], $this->staffUser());
                $this->fail('must not be markable ready');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM order_fulfilment WHERE collection_code_status <> 'none'"));
    }
}
