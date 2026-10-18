<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\HttpException;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;
use Waimart\Services\CodService;
use Waimart\Services\CollectionService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\ReservationService;
use Waimart\Services\SellerOrderService;
use Waimart\Services\SellerOrderWorkflow;
use Waimart\Services\Sellers;

/** Phase 7: fulfilment per seller part, parent roll-up, stock consumption points, rejection/refunds, partial pickup. */
final class SellerFulfilmentTest extends DbTestCase
{
    use PlacesOrders;

    private int $seller;
    private int $sellerUser;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        ReservationService::$faultInjector = null;
        $this->seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
        $this->sellerUser = $this->db->insert('users', ['email' => 'kamba@example.com', 'password_hash' => 'x', 'first_name' => 'K', 'last_name' => 'C']);
        $this->db->insert('seller_users', ['seller_id' => $this->seller, 'user_id' => $this->sellerUser, 'role' => 'owner']);
        $this->staff = $this->staffUser();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    /** Mixed order (Official Store item + marketplace item), confirmed. @return array{order:array,market:array,official:array,mv:int,ov:int} */
    private function confirmedOrder(string $method = 'delivery', string $payment = 'mpesa_till'): array
    {
        $mv = $this->makeVariant(5, '2000.00', null, 'Default', $this->seller)['variant_id'];
        $ov = $this->makeVariant(5, '1000.00')['variant_id'];
        $o = $this->placeOrder([[$mv, 1], [$ov, 1]], $payment, $method);
        if ($payment !== 'cod') {
            $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        }
        (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staff], 'Confirmed');
        $parts = $this->db->all('SELECT so.* FROM seller_orders so WHERE so.order_id = ? ORDER BY so.id', [$o['id']]);
        $market = current(array_filter($parts, fn ($p) => (int) $p['seller_id'] === $this->seller));
        $official = current(array_filter($parts, fn ($p) => (int) $p['seller_id'] === Sellers::platformId($this->db)));
        return ['order' => $o, 'market' => $market, 'official' => $official, 'mv' => $mv, 'ov' => $ov];
    }

    private function seller(): array
    {
        return ['type' => 'seller', 'id' => $this->sellerUser, 'seller_id' => $this->seller];
    }

    private function staffActor(): array
    {
        return ['type' => 'staff', 'id' => $this->staff];
    }

    private function step(int $so, string $to, array $actor, array $opts = []): array
    {
        return (new SellerOrderWorkflow($this->db))->transition($so, $to, $actor, $opts);
    }

    private function orderStatus(int $id): string
    {
        return (string) $this->db->value('SELECT status FROM orders WHERE id = ?', [$id]);
    }

    public function testDeliveryJourneyConsumesStockAtTheRightMomentAndRollsUp(): void
    {
        $x = $this->confirmedOrder();
        $m = (int) $x['market']['id'];
        $p = (int) $x['official']['id'];
        $oid = (int) $x['order']['id'];
        $this->assertSame(['pending_acceptance', 'accepted'], [$x['market']['status'], $x['official']['status']]);

        $this->step($m, 'accepted', $this->seller());
        $this->step($m, 'processing', $this->seller());
        $this->step($m, 'packed', $this->seller(), ['note' => '1 parcel']);
        $this->assertSame([5, 1], array_values($this->levels($x['mv'])), 'packed: still only reserved');

        $this->step($m, 'handed_over', $this->staffActor());
        $this->assertSame([4, 0], array_values($this->levels($x['mv'])), 'marketplace stock leaves the seller at hand-over');
        $this->assertSame([5, 1], array_values($this->levels($x['ov'])), 'Official Store item untouched');

        $this->step($p, 'processing', $this->staffActor());
        $this->step($p, 'packed', $this->staffActor());
        $this->assertSame('packed', $this->orderStatus($oid), 'parent = least advanced part');
        $this->step($p, 'dispatched', $this->staffActor(), ['tracking_reference' => 'RIDER-7']);
        $this->assertSame([4, 0], array_values($this->levels($x['ov'])), 'Official Store stock leaves at dispatch');
        $this->assertSame('packed', $this->orderStatus($oid), 'the marketplace part is not dispatched yet');

        $this->step($m, 'dispatched', $this->staffActor());
        $this->assertSame('dispatched', $this->orderStatus($oid));
        $this->step($p, 'delivered', $this->staffActor());
        $this->assertSame('dispatched', $this->orderStatus($oid), 'not delivered until every part is');
        $this->step($m, 'out_for_delivery', $this->staffActor());
        $this->assertSame('out_for_delivery', $this->orderStatus($oid), 'least advanced part is now out for delivery');
        $this->step($m, 'delivered', $this->staffActor());
        $this->assertSame('delivered', $this->orderStatus($oid));
        $this->assertNotNull($this->db->value('SELECT completed_at FROM orders WHERE id = ?', [$oid]));
        $this->assertSame(2, (int) $this->db->value('SELECT SUM(fulfilled_qty) FROM order_items WHERE order_id = ?', [$oid]));
        $this->assertSame('RIDER-7', $this->db->value('SELECT tracking_reference FROM seller_orders WHERE id = ?', [$p]));
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'seller_order_dispatched' AND channel = 'sms'"), 'customer told about each dispatched part');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'consume' AND actor_type = 'staff' AND variant_id = ?", [$x['mv']]));
        $this->assertInventoryConsistent();
    }

    public function testWhoMayDoWhat(): void
    {
        $x = $this->confirmedOrder();
        $m = (int) $x['market']['id'];
        $p = (int) $x['official']['id'];
        $other = $this->db->insert('sellers', ['name' => 'Other Shop', 'slug' => 'other-shop']);
        $deny = function (callable $fn, string $why, int $status = 0) {
            try {
                $fn();
                $this->fail($why);
            } catch (HttpException $e) {
                $this->assertSame($status ?: 404, $e->status, $why);
            } catch (ValidationException $e) {
                $this->assertSame(0, $status, $why . ': ' . $e->getMessage());
            }
        };
        $deny(fn () => $this->step($m, 'accepted', ['type' => 'seller', 'id' => 1, 'seller_id' => $other]), 'another seller cannot touch this part', 404);
        $deny(fn () => $this->step($p, 'processing', $this->seller()), "a seller cannot touch the Official Store's part", 404);
        $deny(fn () => $this->step($m, 'accepted', $this->staffActor()), 'staff cannot accept on behalf of a marketplace seller');
        $this->step($m, 'accepted', $this->seller());
        $this->step($m, 'processing', $this->seller());
        $this->step($m, 'packed', $this->seller());
        $deny(fn () => $this->step($m, 'handed_over', $this->seller()), 'sellers do not confirm their own hand-over');
        $deny(fn () => $this->step($m, 'dispatched', $this->staffActor()), 'Waimart must receive the parcel first');
        $deny(fn () => $this->step($m, 'ready_for_collection', $this->staffActor()), 'delivery orders are not collected');
        $this->expectException(ValidationException::class);
        (new OrderWorkflow($this->db))->transition((int) $x['order']['id'], 'processing', $this->staffActor());   // order-level fulfilment refused
    }

    public function testSellersCannotActBeforeTheOrderIsConfirmed(): void
    {
        $mv = $this->makeVariant(5, '2000.00', null, 'Default', $this->seller)['variant_id'];
        $o = $this->placeOrder([[$mv, 1]], 'mpesa_till');
        $so = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$o['id']]);
        try {
            $this->step($so, 'accepted', $this->seller());
            $this->fail('cannot accept an unpaid order');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not confirmed', $e->getMessage());
        }
        $this->assertNull((new SellerOrderService($this->db))->sellerView($this->seller, $so), 'unconfirmed orders are not shown to sellers');
        $this->assertSame([], (new SellerOrderService($this->db))->forSeller($this->seller));
    }

    public function testRejectionOfAPaidPartRefundsItAndCancellingTheRestRefundsExactlyTheTotal(): void
    {
        $x = $this->confirmedOrder();
        $oid = (int) $x['order']['id'];
        $this->step((int) $x['market']['id'], 'rejected', $this->seller(), ['reason' => 'Item damaged in storage']);
        $this->assertSame([5, 0], array_values($this->levels($x['mv'])), "the seller's stock is released");
        $this->assertSame([5, 1], array_values($this->levels($x['ov'])), "the other part keeps its reservation");
        $this->assertSame(['2000.00', (int) $x['market']['id']], array_values(array_map(fn ($v) => is_numeric($v) && str_contains((string) $v, '.') ? $v : (int) $v,
            (array) $this->db->one('SELECT amount, seller_order_id FROM refunds WHERE order_id = ?', [$oid]))));
        $this->assertSame('refund_due', $this->db->value('SELECT payment_status FROM orders WHERE id = ?', [$oid]));
        $this->assertSame('confirmed', $this->orderStatus($oid), 'the rest of the order continues');
        $this->assertStringContainsString('cannot be supplied', (string) $this->db->value("SELECT body FROM notifications WHERE template = 'seller_order_withdrawn' AND channel = 'sms'"));

        $this->step((int) $x['official']['id'], 'cancelled', $this->staffActor(), ['reason' => 'Customer asked to cancel']);
        $this->assertSame('cancelled', $this->orderStatus($oid), 'no parts left: the order is cancelled');
        $this->assertSame((string) $x['order']['grand_total'], $this->db->value("SELECT SUM(amount) FROM refunds WHERE order_id = ? AND status = 'due'", [$oid]),
            'part refunds + remaining (delivery fee) = exactly what was paid, never more');
        $this->assertInventoryConsistent();
    }

    public function testCodAmountDropsWhenAPartIsDeclined(): void
    {
        $x = $this->confirmedOrder('pickup', 'cod');
        $this->step((int) $x['market']['id'], 'rejected', $this->seller(), ['reason' => 'Out of this colour']);
        $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$x['order']['id']]);
        $this->assertSame(Money::sub((string) $order['grand_total'], '2000.00'), SellerOrderService::amountDue($order));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM refunds'), 'nothing was paid, so nothing to refund');
        $p = (int) $x['official']['id'];
        foreach (['processing', 'packed', 'ready_for_collection'] as $to) {
            $this->step($p, $to, $this->staffActor());
        }
        $code = CollectionService::codeFor($order + ['status' => (string) $this->db->value('SELECT status FROM orders WHERE id = ?', [$order['id']])]);
        (new CollectionService($this->db))->verifyAndCollect((int) $order['id'], (string) $code, $this->staff, true);
        try {
            (new CodService($this->db))->record((int) $order['id'], (string) $order['grand_total'], $this->staff, null);
            $this->fail('the full original total is no longer expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors);
        }
        (new CodService($this->db))->record((int) $order['id'], SellerOrderService::amountDue($order), $this->staff, null);
        $this->assertSame('cod_collected', $this->db->value('SELECT payment_status FROM orders WHERE id = ?', [$order['id']]));
    }

    public function testPartsAreCollectedSeparatelyWithOneCode(): void
    {
        $x = $this->confirmedOrder('pickup', 'cod');
        $oid = (int) $x['order']['id'];
        $m = (int) $x['market']['id'];
        $p = (int) $x['official']['id'];
        foreach (['accepted', 'processing', 'packed'] as $to) {
            $this->step($m, $to, $this->seller());
        }
        $this->step($m, 'handed_over', $this->staffActor());
        $this->step($m, 'ready_for_collection', $this->staffActor());
        $sms = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'seller_order_ready' AND channel = 'sms'");
        $this->assertStringContainsString('ready for collection', $sms);
        $this->assertStringNotContainsString((string) Crypto::decrypt((string) $this->db->value('SELECT collection_code_enc FROM order_fulfilment WHERE order_id = ?', [$oid])), $sms,
            'the stored copy never contains the code');
        $order = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$oid]);
        $code = (string) CollectionService::codeFor($order);
        $this->assertSame(8, strlen($code), 'customer can see the code once a part is ready');

        try {
            (new CollectionService($this->db))->verifyAndCollect($oid, 'WRONG123', $this->staff, true);
            $this->fail('wrong code');
        } catch (ValidationException) {
        }
        (new CollectionService($this->db))->verifyAndCollect($oid, $code, $this->staff, true);
        $this->assertSame('collected', $this->db->value('SELECT status FROM seller_orders WHERE id = ?', [$m]));
        $this->assertSame('accepted', $this->db->value('SELECT status FROM seller_orders WHERE id = ?', [$p]));
        $this->assertNotSame('collected', $this->orderStatus($oid), 'one part still to come');
        $this->assertSame('active', $this->db->value('SELECT collection_code_status FROM order_fulfilment WHERE order_id = ?', [$oid]), 'code stays valid');

        foreach (['processing', 'packed', 'ready_for_collection'] as $to) {
            $this->step($p, $to, $this->staffActor());
        }
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template = 'seller_order_ready' AND channel = 'sms' AND body LIKE '%code we sent you earlier%'"), 'same code reused');
        (new CollectionService($this->db))->verifyAndCollect($oid, $code, $this->staff, true);
        $this->assertSame('collected', $this->orderStatus($oid));
        $this->assertSame('used', $this->db->value('SELECT collection_code_status FROM order_fulfilment WHERE order_id = ?', [$oid]));
        $this->assertSame([4, 0], array_values($this->levels($x['ov'])), 'Official Store stock consumed at collection');
        $this->assertInventoryConsistent();
    }

    public function testWholeOrderCannotBeCancelledAfterAHandOverButPartsCan(): void
    {
        $x = $this->confirmedOrder();
        $m = (int) $x['market']['id'];
        foreach (['accepted', 'processing', 'packed'] as $to) {
            $this->step($m, $to, $this->seller());
        }
        $this->step($m, 'handed_over', $this->staffActor());
        try {
            (new OrderWorkflow($this->db))->transition((int) $x['order']['id'], 'cancelled', $this->staffActor(), null, ['reason' => 'Changed mind']);
            $this->fail('cannot cancel the whole order after a hand-over');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('handed over', $e->getMessage());
        }
        try {
            $this->step($m, 'cancelled', $this->staffActor(), ['reason' => 'Too late now']);
            $this->fail('a handed-over part cannot be cancelled');
        } catch (ValidationException) {
        }
        $this->step((int) $x['official']['id'], 'cancelled', $this->staffActor(), ['reason' => 'Customer changed mind']);
        $this->assertSame('cancelled', $this->db->value('SELECT status FROM seller_orders WHERE id = ?', [$x['official']['id']]));
        $this->assertNotSame('cancelled', $this->orderStatus((int) $x['order']['id']), 'the handed-over part continues');
    }

    public function testSellerViewShowsOnlyWhatIsNeededToFulfil(): void
    {
        $x = $this->confirmedOrder();
        $view = (array) (new SellerOrderService($this->db))->sellerView($this->seller, (int) $x['market']['id']);
        $json = json_encode($view);
        foreach (['customer_phone', 'customer_email', 'location'] as $field) {
            $this->assertArrayNotHasKey($field, $view);
        }
        $this->assertStringNotContainsString((string) $x['order']['customer_phone'], (string) $json);
        $this->assertStringNotContainsString('Test Rd', (string) $json, 'no street address');
        $this->assertSame('Test', $view['customer_first_name']);
        $this->assertCount(1, $view['items'], "only this seller's items");
        $this->assertNull((new SellerOrderService($this->db))->sellerView($this->seller, (int) $x['official']['id']), "the Official Store's part is not visible to the seller");
    }
}
