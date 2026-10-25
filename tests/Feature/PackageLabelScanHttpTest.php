<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Barcode;
use Waimart\Services\CollectionService;
use Waimart\Services\OrderWorkflow;
use Waimart\Tests\Integration\PlacesOrders;

/**
 * Package labels and station scanning: the printable label carries the store, customer, phone, customer number,
 * station, products and a Code 128 barcode of the order number. Scanning it at the station on arrival marks it ready
 * and tells the customer; scanning it again at hand-over opens it, and handing over (customer's code and ID) finishes
 * the order with a thank-you message.
 */
final class PackageLabelScanHttpTest extends FeatureTestCase
{
    use PlacesOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    /** @return array<string,mixed> a pickup order being prepared */
    private function preparing(string $method = 'pickup'): array
    {
        $v = $this->makeVariant(5, '450.00')['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'cod', $method, ['customer_name' => 'Amina Otieno', 'customer_phone' => '0728000111', 'customer_email' => 'amina@example.com']);
        $wf = new OrderWorkflow($this->db);
        $wf->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staffUser()]);
        $wf->transition((int) $o['id'], 'processing', ['type' => 'staff', 'id' => $this->staffUser()]);
        return $o;
    }

    private function sent(int $orderId, string $template): string
    {
        return (string) $this->db->value("SELECT body FROM notifications WHERE order_id = ? AND template = ? AND channel = 'sms' ORDER BY id DESC LIMIT 1", [$orderId, $template]);
    }

    public function testLabelCarriesEverythingAndIsPrintable(): void
    {
        $o = $this->preparing();
        $this->loginStaff('fulfilment_staff');
        $page = $this->get('/admin/orders/' . $o['id'] . '/label')['body'];
        $this->assertStringContainsString('@page { size: 100mm 150mm', $page, 'sized for a 100 x 150 mm sticker');
        foreach (['PICKUP', 'Station', 'P1', 'Amina Otieno', '0728 000 111', 'Guest', $o['order_number'], '2 × ', 'COLLECT CASH', 'scan on arrival'] as $want) {
            $this->assertStringContainsString($want, $page, "label shows $want");
        }
        $this->assertStringContainsString('aria-label="Barcode for order ' . $o['order_number'] . '"', $page);
        $this->assertSame(substr_count(Barcode::svg($o['order_number']), '<rect'), substr_count($page, '<rect'), 'the barcode is drawn');
        $this->assertStringNotContainsString('wm-admin-side', $page, 'only the label, without the admin menu');
        $this->assertStringContainsString('js/label.js', $page, 'Print button');

        $other = $this->preparing();
        $both = $this->get('/admin/labels?orders=' . $o['id'] . ',' . $other['id'])['body'];
        $this->assertSame(2, substr_count($both, 'class="sheet"'), 'one label per page');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.label_printed' AND entity_id = ?", [$o['id']]));

        $this->post('/logout', [], '/admin');
        $this->loginStaff('content_marketing');
        $this->assertSame(403, $this->get('/admin/orders/' . $o['id'] . '/label')['status'], 'staff who cannot see orders cannot print labels');
    }

    public function testScanOnArrivalThenScanAtHandOverFinishesTheOrder(): void
    {
        $o = $this->preparing();
        $this->loginStaff('delivery_logistics');   // station attendant
        $desk = $this->get('/admin/collections')['body'];
        $this->assertStringContainsString('Scan a package', $desk);

        // 1st scan (arrival). Scanners send exactly the code; lower case and spaces typed by hand are fine too.
        $r = $this->post('/admin/collections/scan', ['code' => ' ' . strtolower($o['order_number']) . ' '], '/admin/collections');
        $this->assertStringContainsString('has arrived at Station', $this->follow($r)['body']);
        $this->assertSame('ready_for_collection', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertStringContainsString('has arrived at Station', $this->sent((int) $o['id'], 'order_ready_for_collection'), 'customer told by SMS');
        $this->assertNotSame('', (string) $this->db->value("SELECT body FROM notifications WHERE order_id = ? AND template = 'order_ready_for_collection' AND channel = 'email'", [$o['id']]), 'and by email');

        // 2nd scan (hand-over): opens the parcel; the customer's code is still needed.
        $r = $this->post('/admin/collections/scan', ['code' => $o['order_number']], '/admin/collections');
        $this->assertStringEndsWith('/admin/collections/' . $o['id'], $r['headers']['location']);
        $this->assertStringContainsString('Ask the customer for their collection code', $this->follow($r)['body']);
        $this->assertSame('ready_for_collection', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]), 'scanning alone does not hand over');

        $code = (string) CollectionService::codeFor($this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]));
        $desk = '/admin/collections/' . $o['id'];
        $this->post($desk, ['code' => $code, 'id_checked' => '1'], $desk);
        $this->assertSame('collected', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]), 'finished');
        $thanks = $this->sent((int) $o['id'], 'order_collected');
        $this->assertStringContainsString('collected order ' . $o['order_number'] . ' from Station', $thanks);
        $this->assertStringContainsString('Continue shopping with us', $thanks);

        // Scanning a finished parcel again says so.
        $again = $this->follow($this->post('/admin/collections/scan', ['code' => $o['order_number']], '/admin/collections'))['body'];
        $this->assertStringContainsString('was already collected', $again);
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.parcel_scanned' AND entity_id = ?", [$o['id']]), 'every scan is recorded');
    }

    public function testScanningTheWrongThingIsExplained(): void
    {
        $this->loginStaff('delivery_logistics');
        $r = $this->follow($this->post('/admin/collections/scan', ['code' => 'XX-NOTHING1'], '/admin/collections'))['body'];
        $this->assertStringContainsString('No order matches', $r);

        $home = $this->preparing('delivery');
        $r = $this->follow($this->post('/admin/collections/scan', ['code' => $home['order_number']], '/admin/collections'))['body'];
        $this->assertStringContainsString('home-delivery order', $r);

        $v = $this->makeVariant(3)['variant_id'];
        $early = $this->placeOrder([[$v, 1]], 'cod', 'pickup');   // not confirmed or sent yet
        $r = $this->follow($this->post('/admin/collections/scan', ['code' => $early['order_number']], '/admin/collections'))['body'];
        $this->assertStringContainsString('has not been sent to the station yet', $r);
        $this->assertSame('pending_confirmation', $this->db->value('SELECT status FROM orders WHERE id = ?', [$early['id']]));
    }
}
