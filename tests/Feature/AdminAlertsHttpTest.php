<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

/**
 * Work waiting for staff: red counts in the admin menu, the "orders waiting for your action" reminder and the
 * 30-second refresh. Orders are counted only for staff who can act on them (confirm COD, verify M-Pesa, resolve).
 */
final class AdminAlertsHttpTest extends FeatureTestCase
{
    private function waiting(array $statuses): int
    {
        $in = implode(',', array_fill(0, count($statuses), '?'));
        return (int) $this->db->value("SELECT COUNT(*) FROM orders WHERE status IN ($in)", $statuses);
    }

    private function seedWaitingOrders(): void
    {
        $v = $this->makeVariant(10, '800.00')['variant_id'];
        $this->makeOrder($v, 1, ['payment_method' => 'cod', 'status' => 'pending_confirmation', 'payment_status' => 'cod_pending']);
        $this->makeOrder($v, 1, ['payment_method' => 'cod', 'status' => 'pending_confirmation', 'payment_status' => 'cod_pending']);
        $this->makeOrder($v, 1, ['status' => 'awaiting_verification', 'payment_status' => 'awaiting_verification']);
        $this->makeOrder($v, 1, ['status' => 'awaiting_payment']);   // the customer has not paid yet: nothing to act on
    }

    /** @return array<string,mixed> */
    private function alerts(): array
    {
        $r = $this->get('/admin/alerts');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('application/json', $r['headers']['content-type'] ?? '');
        $this->assertSame('no-store', $r['headers']['cache-control'] ?? '');
        return json_decode($r['body'], true);
    }

    public function testSuperAdminSeesRedCountAndReminder(): void
    {
        $this->seedWaitingOrders();
        $n = $this->waiting(['pending_confirmation', 'awaiting_verification', 'requires_resolution']);
        $this->assertGreaterThanOrEqual(3, $n);
        $this->loginStaff('super_admin');

        $page = $this->get('/admin/orders')['body'];
        $this->assertMatchesRegularExpression('#data-badge-for="/admin/orders">' . ($n > 99 ? '99\+' : $n) . '<#', $page, 'red count on Orders');
        $this->assertStringContainsString('id="wmPendingPop"', $page);
        $this->assertStringContainsString('data-count="' . $n . '"', $page);
        $this->assertStringContainsString("$n orders are waiting for your action.", $page);
        $this->assertStringContainsString('status=pending_confirmation', $page, 'Open orders goes to the COD orders to confirm');

        $j = $this->alerts();
        $this->assertSame($n, $j['badges']['/admin/orders']);
        $this->assertSame($n, $j['pending']['count']);
        $this->assertSame((int) $this->db->value("SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending'"), $j['badges']['/admin/payments']);
        $this->assertArrayHasKey('/admin/reviews', $j['badges']);
    }

    public function testDashboardLeadsWithColouredPriorityTiles(): void
    {
        $this->seedWaitingOrders();
        $this->loginStaff('super_admin');
        $page = $this->get('/admin')['body'];

        $this->assertStringContainsString('Priority actions', $page);
        $cod = $this->waiting(['pending_confirmation']);
        $this->assertMatchesRegularExpression('#wm-tier-urgent" href="[^"]*/admin/orders\?status=pending_confirmation">.*?wm-priority-count">' . $cod . '<#s', $page,
            'a red, clickable tile with the number of COD orders to confirm');
        $this->assertMatchesRegularExpression('#wm-tier-follow" href="[^"]*status=awaiting_payment">.*?wm-priority-count">' . $this->waiting(['awaiting_payment']) . '<#s', $page,
            'new orders not yet paid for get a follow-up tile');
        $this->assertLessThan(strpos($page, 'Booked sales per day'), strpos($page, 'Priority actions'), 'priority tiles come before the sales chart');
        $this->assertLessThan(strpos($page, 'wm-tier-follow'), strpos($page, 'wm-tier-urgent'), 'most urgent first');

        $this->post('/logout', [], '/admin');
        $this->loginStaff('inventory_clerk');   // sees orders, cannot confirm COD or verify payments
        $page = $this->get('/admin')['body'];
        $this->assertStringNotContainsString('COD orders to confirm', $page);
        $this->assertStringNotContainsString('M-Pesa payments to verify', $page);
    }

    public function testEachRoleCountsOnlyWhatItActsOn(): void
    {
        $this->seedWaitingOrders();
        $this->loginStaff('finance');   // verifies M-Pesa and resolves, does not confirm COD
        $j = $this->alerts();
        $this->assertSame($this->waiting(['awaiting_verification', 'requires_resolution']), $j['badges']['/admin/orders']);
        $this->assertStringContainsString('status=awaiting_verification', $j['pending']['url']);
        $this->assertArrayNotHasKey('/admin/reviews', $j['badges'], 'no counts for queues finance cannot open');

        $this->post('/logout', [], '/admin');
        $this->loginStaff('fulfilment_staff');   // confirms COD, does not verify payments
        $this->assertSame($this->waiting(['pending_confirmation']), $this->alerts()['badges']['/admin/orders']);

        $this->post('/logout', [], '/admin');
        $this->loginStaff('support');   // sees orders but acts on none of these steps: no count, no reminder
        $j = $this->alerts();
        $this->assertArrayNotHasKey('/admin/orders', $j['badges']);
        $this->assertNull($j['pending']);
        $this->assertStringNotContainsString('data-badge-for="/admin/orders"', $this->get('/admin/orders')['body']);
    }

    public function testRefreshNeedsStaffAndDoesNotEatTheNextPagesMessage(): void
    {
        $this->assertNotSame(200, $this->get('/admin/alerts')['status'], 'signed-out visitors get no counts');
        $this->post('/logout', [], '/');   // forget where that visit wanted to go

        // A refresh after the session ended: a plain 401, and signing in again still leads to the dashboard.
        $this->requestHeaders = ['X-WM-Background: 1'];
        $r = $this->get('/admin/alerts');
        $this->requestHeaders = [];
        $this->assertSame(401, $r['status']);
        $this->loginStaff('super_admin');   // asserts the redirect goes to /admin, not to /admin/alerts
        $out = $this->post('/logout', [], '/admin');
        $this->requestHeaders = ['X-WM-Background: 1'];
        $this->get('/admin/alerts');   // a refresh lands between the sign-out and the next page
        $this->requestHeaders = [];
        $this->assertStringContainsString('You have been signed out.', $this->follow($out)['body'], 'the flash message survives the background refresh');
    }
}
