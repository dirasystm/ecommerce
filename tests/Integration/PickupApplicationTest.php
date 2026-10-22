<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\ValidationException;
use Waimart\Services\DeliveryService;
use Waimart\Services\PickupApplicationService;

/** Businesses apply to become pickup stations; a super admin approves (creating the station) or rejects. */
final class PickupApplicationTest extends DbTestCase
{
    private PickupApplicationService $svc;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PickupApplicationService($this->db);
        $this->admin = $this->db->insert('users', ['email' => 'boss@example.com', 'password_hash' => 'x', 'first_name' => 'Boss', 'last_name' => 'Admin', 'is_staff' => 1]);
        $this->db->exec("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'super_admin'", [$this->admin]);
    }

    /** @return array<string,mixed> */
    private function form(array $over = []): array
    {
        $day = ['open' => '08:00', 'close' => '18:00'];
        return $over + [
            'business_name' => 'Mama Njeri Supplies', 'business_type' => 'retail_shop', 'contact_name' => 'Njeri Wambui',
            'phone' => '0722 555 101', 'email' => 'njeri@example.com', 'county_id' => '31', 'town' => 'Nanyuki',
            'address' => 'Kenyatta Avenue, Mt Kenya Plaza, shop 4', 'landmark' => 'Opposite Equity Bank',
            'map_url' => 'https://www.google.com/maps/place/x/@0.0074,37.0731,17z', 'storage_space' => 'medium', 'daily_capacity' => '30',
            'secure_storage' => '1', 'accepts_cod' => '1', 'accept_terms' => '1',
            'hours' => ['mon' => $day, 'tue' => $day, 'wed' => $day, 'thu' => $day, 'fri' => $day, 'sat' => ['open' => '09:00', 'close' => '16:00'], 'sun' => ['closed' => '1']],
        ];
    }

    public function testApplyingRecordsTheApplicationAndTellsEveryone(): void
    {
        $a = $this->svc->apply($this->form(), null, '10.0.0.1');
        $this->assertMatchesRegularExpression('/^PS-[A-HJ-NP-Z2-9]{8}$/', $a['reference']);
        $this->assertSame(['pending', '254722555101', 'Mon–Fri 8:00 am – 6:00 pm; Sat 9:00 am – 4:00 pm; Sun closed', '0.0074000', '37.0731000'],
            [$a['status'], $a['phone'], $a['opening_hours'], $a['latitude'], $a['longitude']], 'coordinates read from the Maps link');
        $sent = $this->db->all('SELECT template, channel, recipient FROM notifications ORDER BY id');
        $this->assertContains(['template' => 'pickup_application_received', 'channel' => 'email', 'recipient' => 'njeri@example.com'], $sent);
        $this->assertContains(['template' => 'pickup_application_received', 'channel' => 'sms', 'recipient' => '254722555101'], $sent);
        $this->assertContains(['template' => 'pickup_application_new', 'channel' => 'email', 'recipient' => 'boss@example.com'], $sent, 'super admins are told');

        $this->assertSame((int) $a['id'], (int) $this->svc->lookup(strtolower($a['reference']), '0722555101', '10.0.0.1')['id'], 'status check');
        $this->assertNull($this->svc->lookup($a['reference'], '0700000000', '10.0.0.1'), 'wrong phone');

        try {
            $this->svc->apply($this->form(), null, '10.0.0.2');
            $this->fail('A second pending application for the same phone should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors);
        }
    }

    public function testInvalidApplicationsAreExplained(): void
    {
        $three = ['mon' => ['open' => '08:00', 'close' => '18:00'], 'tue' => ['open' => '08:00', 'close' => '18:00'], 'wed' => ['open' => '08:00', 'close' => '18:00'],
                  'thu' => ['closed' => '1'], 'fri' => ['closed' => '1'], 'sat' => ['closed' => '1'], 'sun' => ['closed' => '1']];
        try {
            $this->svc->apply($this->form(['hours' => $three, 'secure_storage' => '', 'accept_terms' => '', 'county_id' => '999', 'phone' => '12', 'business_type' => 'casino']), null, '10.0.0.3');
            $this->fail('Expected validation errors.');
        } catch (ValidationException $e) {
            foreach (['hours', 'secure_storage', 'accept_terms', 'county_id', 'phone', 'business_type'] as $k) {
                $this->assertArrayHasKey($k, $e->errors, $k);
            }
            $this->assertStringContainsString('at least 5 days', $e->errors['hours']);
        }
        $this->expectException(ValidationException::class);
        $this->svc->apply($this->form(['website' => 'http://spam.example']), null, '10.0.0.4');   // honeypot
    }

    public function testApprovalCreatesALiveStationOfferedAtCheckout(): void
    {
        $a = $this->svc->apply($this->form(), null, '10.0.0.5');
        $full = $this->svc->find((int) $a['id']);
        $station = $this->svc->suggestedStation($full);
        $this->assertSame('LKP-NYK-01', $station['code']);
        $this->assertSame('1', $station['cod_allowed']);

        $pointId = $this->svc->approve((int) $a['id'], $station + ['decision_note' => 'Visited, all good'], $this->admin);
        $p = $this->db->one('SELECT * FROM pickup_points WHERE id = ?', [$pointId]);
        $this->assertSame(['LKP-NYK-01', 'Nanyuki', '1', 31], [$p['code'], $p['town'], (string) $p['is_active'], (int) $p['county_id']]);
        $this->assertContains($pointId, array_map('intval', array_column((new DeliveryService($this->db))->pickupPoints(), 'id')), 'offered at checkout');

        $done = $this->svc->find((int) $a['id']);
        $this->assertSame(['approved', $pointId, $this->admin], [$done['status'], (int) $done['pickup_point_id'], (int) $done['reviewed_by']]);
        $this->assertNotEmpty($this->db->value("SELECT id FROM notifications WHERE template = 'pickup_application_approved' AND channel = 'sms'"));
        $this->assertSame('LKP-NYK-02', $this->svc->suggestedStation($done)['code'], 'next free code');

        $this->expectException(ValidationException::class);
        $this->svc->reject((int) $a['id'], 'Changed our mind about this one', $this->admin);   // already decided
    }

    public function testRejectionNeedsAReasonAndIsSent(): void
    {
        $a = $this->svc->apply($this->form(), null, '10.0.0.6');
        try {
            $this->svc->reject((int) $a['id'], 'no', $this->admin);
            $this->fail('A one-word reason should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors);
        }
        $this->svc->reject((int) $a['id'], 'We already have a station on the same street.', $this->admin);
        $this->assertSame('rejected', $this->svc->find((int) $a['id'])['status']);
        $msg = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'pickup_application_rejected' AND channel = 'email'");
        $this->assertStringContainsString('We already have a station on the same street.', $msg);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM pickup_points WHERE town = ?', ['Nanyuki']), 'no station created');
    }

    public function testCoordinatesOutsideKenyaAreIgnored(): void
    {
        $this->assertSame([null, null], PickupApplicationService::coordinates('51.5', '-0.12', ''), 'London');
        $this->assertSame(['-1.2864000', '36.8172000'], PickupApplicationService::coordinates('-1.2864', '36.8172', ''));
        $this->assertSame(['-4.0435000', '39.6682000'], PickupApplicationService::coordinates('', '', 'https://maps.google.com/?q=-4.0435,39.6682'));
    }
}
