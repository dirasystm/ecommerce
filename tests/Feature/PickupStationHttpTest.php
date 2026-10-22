<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

/** "Become a pickup station" end to end: apply with a photo, check the status, review and approve, then checkout. */
final class PickupStationHttpTest extends FeatureTestCase
{
    private function freshJar(): void
    {
        (new \ReflectionProperty(FeatureTestCase::class, 'jar'))->setValue($this, tempnam(sys_get_temp_dir(), 'wmjar'));
    }

    private function photo(): string
    {
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 0, 91, 61));
        $f = sys_get_temp_dir() . '/shopfront-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $f);
        imagedestroy($im);
        return $f;
    }

    public function testApplyCheckStatusApproveAndOfferAtCheckout(): void
    {
        $page = $this->get('/become-a-pickup-station');
        $this->assertSame(200, $page['status']);
        foreach (['Become a', 'How it works', 'What you need', 'Opening hours', 'Use my current location', 'Submit application'] as $t) {
            $this->assertStringContainsString($t, $page['body']);
        }
        $this->assertStringContainsString('href="/become-a-pickup-station"', $this->get('/')['body'], 'footer link');

        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
        $day = ['open' => '08:00', 'close' => '18:00'];
        $data = ['_csrf' => html_entity_decode($m[1]), 'business_name' => 'Wambui Pharmacy', 'business_type' => 'pharmacy', 'contact_name' => 'Grace Wambui',
            'phone' => '0733 600 700', 'email' => 'grace@example.com', 'county_id' => '31', 'town' => 'Nanyuki', 'address' => 'Lumumba Road, shop 2',
            'storage_space' => 'small', 'secure_storage' => '1', 'accept_terms' => '1', 'latitude' => '0.0150000', 'longitude' => '37.0700000'];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $d) {
            $data["hours[$d][open]"] = $day['open'];
            $data["hours[$d][close]"] = $day['close'];
        }
        $data['hours[sun][closed]'] = '1';
        $r = $this->http('POST', '/become-a-pickup-station', $data, ['photo' => $this->photo()], '/become-a-pickup-station');
        $this->assertSame('/become-a-pickup-station/thank-you', $r['headers']['location'] ?? null, substr($r['body'], 0, 300));
        $thanks = $this->follow($r)['body'];
        $this->assertMatchesRegularExpression('/PS-[A-HJ-NP-Z2-9]{8}/', $thanks);
        preg_match('/(PS-[A-HJ-NP-Z2-9]{8})/', $thanks, $ref);
        $app = $this->db->one('SELECT * FROM pickup_station_applications WHERE reference = ?', [$ref[1]]);
        $this->assertNotEmpty($app['photo_stored_name'], 'photo stored privately');
        $this->assertSame('Mon–Sat 8:00 am – 6:00 pm; Sun closed', $app['opening_hours']);

        // Status check (reference + phone).
        $check = $this->get('/become-a-pickup-station/check')['body'];
        preg_match('/name="_csrf" value="([^"]+)"/', $check, $m);
        $s = $this->http('POST', '/become-a-pickup-station/check', ['_csrf' => html_entity_decode($m[1]), 'reference' => strtolower($ref[1]), 'phone' => '0733600700'], [], '/become-a-pickup-station/check');
        $this->assertStringContainsString('Being reviewed', $s['body']);

        // Staff without the approval permission cannot see applications; a super admin can review and approve.
        $this->freshJar();
        $this->loginStaff('support');
        $this->assertSame(403, $this->get('/admin/pickup-points/applications')['status']);
        $this->assertSame(403, $this->get('/admin/pickup-points/applications/' . $app['id'] . '/photo')['status']);
        $this->post('/logout', [], '/');
        $this->freshJar();
        $this->loginStaff('super_admin');
        $list = $this->get('/admin/pickup-points/applications')['body'];
        $this->assertStringContainsString('Wambui Pharmacy', $list);
        $show = $this->get('/admin/pickup-points/applications/' . $app['id']);
        $this->assertSame(200, $show['status']);
        $this->assertStringContainsString('value="LKP-NYK-01"', $show['body'], 'suggested station code');
        $photo = $this->get('/admin/pickup-points/applications/' . $app['id'] . '/photo');
        $this->assertSame([200, 'image/png'], [$photo['status'], $photo['headers']['content-type'] ?? null]);

        preg_match('/name="_csrf" value="([^"]+)"/', $show['body'], $m);
        $approve = ['_csrf' => html_entity_decode($m[1]), 'code' => 'LKP-NYK-01', 'name' => 'Basemall Pickup – Wambui Pharmacy', 'county_id' => '31', 'town' => 'Nanyuki',
            'address' => 'Lumumba Road, shop 2', 'opening_hours' => $app['opening_hours'], 'pickup_fee' => '0', 'latitude' => '0.0150000', 'longitude' => '37.0700000'];
        $done = $this->http('POST', '/admin/pickup-points/applications/' . $app['id'] . '/approve', $approve, [], '/admin/pickup-points/applications/' . $app['id']);
        $this->assertStringContainsString('/admin/pickup-points/', $done['headers']['location'] ?? '');
        $this->assertSame('approved', $this->db->value('SELECT status FROM pickup_station_applications WHERE id = ?', [$app['id']]));

        // The new station is offered at checkout, with what the suggestions rank on.
        $this->post('/logout', [], '/');
        $this->freshJar();
        $v = $this->makeVariant(5, '900.00')['variant_id'];
        $slug = (string) $this->db->value('SELECT p.slug FROM products p JOIN product_variants v ON v.product_id = p.id WHERE v.id = ?', [$v]);
        $this->post('/cart/add', ['variant_id' => (string) $v, 'quantity' => '1'], '/product/' . $slug);
        $checkout = $this->get('/checkout')['body'];
        $this->assertStringContainsString('Basemall Pickup – Wambui Pharmacy', $checkout);
        $this->assertStringContainsString('data-station-suggest', $checkout);
        $this->assertMatchesRegularExpression('/data-county-id="31" data-county="Laikipia" data-town="Nanyuki"[^>]*data-lat="0.0150000" data-lng="37.0700000"/s', $checkout);
    }
}
