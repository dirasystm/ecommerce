<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Services\Marketing\Consent;

/**
 * Marketing Intelligence over HTTP: permissions, campaigns and promotional links (no open redirects, crawlers get the
 * share preview and are not counted, attribution to the order), leads masking and export, newsletter, unsubscribe,
 * guests' privacy choice and the customer's own history, privacy page and data export.
 */
final class MarketingHttpTest extends FeatureTestCase
{
    private function freshJar(): void
    {
        (new \ReflectionProperty(FeatureTestCase::class, 'jar'))->setValue($this, tempnam(sys_get_temp_dir(), 'wmjar'));
    }

    private function csrf(string $page): string
    {
        return preg_match('/name="_csrf" value="([^"]+)"/', $this->get($page)['body'], $m) ? html_entity_decode($m[1]) : '';
    }

    public function testPermissionsAndEveryPageRenders(): void
    {
        $this->loginStaff('support');
        $this->assertSame(403, $this->get('/admin/marketing')['status'], 'support staff have no marketing access');
        $this->post('/logout', [], '/');

        $this->freshJar();
        $this->loginStaff('content_marketing');
        foreach (['/admin/marketing', '/admin/marketing?range=custom&from=2026-01-01&to=2026-01-31&model=first', '/admin/marketing/campaigns',
                  '/admin/marketing/campaigns/new', '/admin/marketing/leads', '/admin/marketing/segments', '/admin/marketing/segments/repeat',
                  '/admin/marketing/consent', '/admin/marketing/automations', '/admin/marketing/recommendations', '/admin/marketing/settings'] as $page) {
            $r = $this->get($page);
            $this->assertSame(200, $r['status'], $page . ': ' . substr(strip_tags($r['body']), 0, 300));
            $this->assertStringContainsString('Marketing Intelligence', $r['body'], $page);
        }
        $this->assertSame(403, $this->get('/admin/marketing/leads/export')['status'], 'contact details need marketing.leads');
        $this->assertSame(404, $this->get('/admin/marketing/segments/nonsense')['status']);
        $this->post('/logout', [], '/');

        $this->freshJar();
        $this->loginStaff('manager');
        $this->assertSame(200, $this->get('/admin/marketing')['status']);
        $this->assertSame(403, $this->get('/admin/marketing/campaigns/new')['status'], 'managers can view, not change');
        $this->assertSame(403, $this->get('/admin/marketing/settings')['status']);
    }

    public function testCampaignLinkRedirectsInsideTheShopCountsHumansAndCreditsTheOrder(): void
    {
        $v = $this->makeVariant(8, '2499.00');
        $slug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$v['product_id']]);
        $this->loginStaff('super_admin');

        $r = $this->post('/admin/marketing/campaigns', ['name' => 'Phones on Facebook', 'objective' => 'sales', 'channel' => 'social', 'platform' => 'facebook',
            'cost' => '1500'], '/admin/marketing/campaigns/new');
        $this->assertSame(303, $r['status'], substr(strip_tags($r['body']), 0, 300));
        $cid = (int) $this->db->value("SELECT id FROM marketing_campaigns WHERE name = 'Phones on Facebook'");

        // Destinations are always pages of this shop.
        foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example', '/a/../../etc'] as $bad) {
            $this->post('/admin/marketing/campaigns/' . $cid . '/links', ['destination_type' => 'page', 'destination' => $bad], '/admin/marketing/campaigns/' . $cid);
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM campaign_links'), 'external or odd paths are refused');
        $this->post('/admin/marketing/campaigns/' . $cid . '/links', ['destination_type' => 'product', 'destination' => $slug, 'coupon_code' => 'NOPE10'], '/admin/marketing/campaigns/' . $cid);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM campaign_links'), 'a coupon that does not exist is refused (no invented discounts)');

        $r = $this->post('/admin/marketing/campaigns/' . $cid . '/links', ['destination_type' => 'product', 'destination' => $slug, 'cta' => 'Buy now'], '/admin/marketing/campaigns/' . $cid);
        $link = $this->db->one('SELECT * FROM campaign_links WHERE campaign_id = ?', [$cid]);
        $this->assertNotNull($link);
        $this->assertSame('/product/' . $slug, $link['destination_path']);
        $studio = $this->follow($r);
        $this->assertSame(200, $studio['status']);
        foreach (['/go/' . $link['code'], 'KSh 2,499', 'data-qr=', '#Ecommerce', 'Buy now', 'wa.me'] as $t) {
            $this->assertStringContainsString($t, $studio['body'], $t);
        }

        // A link-preview crawler gets the share card and is not counted.
        $this->freshJar();
        $this->userAgent = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
        $card = $this->get('/go/' . $link['code']);
        $this->assertSame(200, $card['status']);
        $this->assertStringContainsString('og:title', $card['body']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM campaign_clicks WHERE is_bot = 0'));

        // A person is sent to the product with campaign tags, counted once as unique.
        $this->userAgent = 'Mozilla/5.0 (Linux; Android 14) Chrome/126.0 Mobile Safari/537.36';
        $go = $this->get('/go/' . $link['code']);
        $this->assertSame(302, $go['status']);
        $this->assertMatchesRegularExpression('#^(https?://[^/]+)?/product/' . preg_quote($slug, '#') . '\?utm_source=facebook&utm_medium=social&utm_campaign=' . $link['code'] . '$#', $go['headers']['location']);
        $this->get('/go/' . $link['code'] . '?via=qr');
        $this->assertSame(['2', '1', '1'], array_values(array_map('strval', $this->db->one('SELECT COUNT(*) AS c, SUM(is_unique) AS u, SUM(via = \'qr\') AS q FROM campaign_clicks WHERE is_bot = 0'))));
        $this->assertSame(303, $this->get('/go/zzzzzzzz')['status'], 'unknown code: homepage');
        $this->assertMatchesRegularExpression('#^(https?://[^/]+)?/$#', $this->get('/go/zzzzzzzz')['headers']['location']);

        // An order credited to the link (as Signals::orderPlaced records it from the attribution cookie; covered in the integration tests).
        $order = $this->makeOrder($v['variant_id'], 1, ['payment_status' => 'verified', 'placed_at' => date('Y-m-d H:i:s')]);
        $this->db->exec('UPDATE orders SET grand_total = 2499.00 WHERE id = ?', [$order['order_id']]);
        $this->db->insert('shop_events', ['event' => 'order_placed', 'visitor_id' => str_repeat('a', 32), 'order_id' => $order['order_id'], 'link_id' => $link['id'], 'first_link_id' => $link['id'],
            'amount' => '2499.00', 'occurred_at' => date('Y-m-d H:i:s')]);
        $this->userAgent = null;
        $this->freshJar();
        $this->loginStaff('super_admin');
        $list = $this->get('/admin/marketing/campaigns')['body'];
        $this->assertStringContainsString('Phones on Facebook', $list);
        $this->assertMatchesRegularExpression('/KSh 2,499(\.00)?<\/td>/', $list, 'paid sales credited to the campaign');
        $this->assertStringContainsString('1.67×', $list, 'ROAS uses the real cost entered: 2,499 ÷ 1,500');

        // Switching the link off sends people home, uncounted.
        $this->post('/admin/marketing/links/' . $link['id'] . '/active', ['active' => '0'], '/admin/marketing/links/' . $link['id']);
        $before = (int) $this->db->value('SELECT COUNT(*) FROM campaign_clicks');
        $this->assertMatchesRegularExpression('#^(https?://[^/]+)?/$#', $this->get('/go/' . $link['code'])['headers']['location']);
        $this->assertSame($before, (int) $this->db->value('SELECT COUNT(*) FROM campaign_clicks'));
    }

    public function testNewsletterLeadsMaskingExportAndUnsubscribe(): void
    {
        $r = $this->post('/newsletter', ['email' => 'Wanjiku@Example.com', 'consent_email' => '1', 'website' => ''], '/');
        $this->assertSame(303, $r['status']);
        $lead = $this->db->one("SELECT * FROM marketing_leads WHERE email = 'wanjiku@example.com'");
        $this->assertNotNull($lead);
        $this->assertSame(1, (int) $lead['consent_email']);
        $this->post('/newsletter', ['email' => 'noconsent@example.com', 'website' => ''], '/');
        $this->assertNull($this->db->value("SELECT id FROM marketing_leads WHERE email = 'noconsent@example.com'"), 'no consent, no lead');

        $this->freshJar();
        $this->loginStaff('content_marketing');
        $leads = $this->get('/admin/marketing/leads')['body'];
        $this->assertStringNotContainsString('wanjiku@example.com', $leads, 'masked without marketing.leads');
        $this->assertStringContainsString('w**', $leads);
        $this->post('/logout', [], '/');
        $this->freshJar();
        $this->loginStaff('super_admin');
        $csv = $this->get('/admin/marketing/leads/export');
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString('wanjiku@example.com', $csv['body']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'marketing.leads_exported'"), 'exports are audited');

        // Unsubscribe link: a tampered signature is refused; the real one needs a button press (mail scanners only GET).
        $url = Consent::unsubscribeUrl('l', (int) $lead['id'], 'email');
        $path = substr((string) parse_url($url, PHP_URL_PATH), (int) strpos((string) parse_url($url, PHP_URL_PATH), '/unsubscribe/'));
        $this->freshJar();
        $bad = substr($path, 0, -1) . (str_ends_with($path, '0') ? '1' : '0');
        $this->assertStringContainsString('not valid', strtolower($this->get($bad)['body']));
        $form = $this->get($path);
        $this->assertSame(200, $form['status']);
        $this->assertSame(1, (int) $this->db->value('SELECT consent_email FROM marketing_leads WHERE id = ?', [$lead['id']]), 'opening the link alone changes nothing');
        $this->post($path, [], $path);
        $this->assertSame(0, (int) $this->db->value('SELECT consent_email FROM marketing_leads WHERE id = ?', [$lead['id']]));
    }

    public function testCustomerHistoryPrivacyChoicesAndExport(): void
    {
        $uid = $this->db->insert('users', ['email' => 'amani@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'first_name' => 'Amani', 'last_name' => 'K', 'phone' => '254712345678']);
        $v = $this->makeVariant(5, '899.00');
        $slug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$v['product_id']]);
        $this->post('/login', ['email' => 'amani@example.com', 'password' => 'Secret123'], '/login');
        $this->get('/product/' . $slug);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM shop_events WHERE user_id = ? AND event = 'product_viewed'", [$uid]));
        $this->assertStringContainsString('Test Product', $this->get('/account/history')['body']);

        $this->post('/account/privacy', ['marketing_email' => '1', 'marketing_sms' => '1', 'frequency' => 'less'], '/account/privacy');
        $u = $this->db->one('SELECT marketing_email, marketing_sms, personalisation, marketing_frequency FROM users WHERE id = ?', [$uid]);
        $this->assertSame(['1', '1', '0', 'less'], array_map('strval', array_values($u)), 'personalisation unticked = off');
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM marketing_consents WHERE user_id = ?', [$uid]), 'each change recorded');

        // With personalisation off, nothing new is recorded.
        $this->get('/product/' . $slug . '?again=1');
        $this->db->exec("UPDATE shop_events SET occurred_at = NOW() - INTERVAL 2 HOUR WHERE user_id = ?", [$uid]);
        $this->get('/product/' . $slug);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM shop_events WHERE user_id = ? AND event = 'product_viewed'", [$uid]));

        $export = $this->get('/account/privacy/export');
        $this->assertSame(200, $export['status']);
        $data = json_decode($export['body'], true);
        $this->assertIsArray($data, substr($export['body'], 0, 200));
        $this->assertSame('amani@example.com', $data['account']['email'] ?? null);

        $this->post('/account/history/forget', ['what' => 'all'], '/account/history');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM shop_events WHERE user_id = ? AND event = \'product_viewed\'', [$uid]));
    }
}
