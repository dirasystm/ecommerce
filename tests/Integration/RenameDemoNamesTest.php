<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Migrator;
use Waimart\Core\Settings;
use Waimart\Services\UpdateSqlGuard;

/** Migration 037: the platform's first name is replaced by the store's name in text customers see, nothing else. */
final class RenameDemoNamesTest extends DbTestCase
{
    private function run037(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/037_rename_demo_names.sql');
        foreach (Migrator::splitStatements($sql) as $s) {
            $this->db->pdo()->exec($s);
        }
    }

    public function testReplacesTheOldNameWithTheStoreNameInVisibleTextOnly(): void
    {
        $saved = Settings::get('store_name', '');
        Settings::set('store_name', 'Shopname');
        try {
            $point = $this->db->insert('pickup_points', ['code' => 'OLD-1', 'name' => 'Waimart Pickup — Moi Avenue', 'county_id' => 47, 'town' => 'CBD', 'address' => 'Moi Ave', 'opening_hours' => '9-5']);
            $v = $this->makeVariant(3);
            $this->db->exec("UPDATE products SET name = 'Waimart Basics Rice 5kg', slug = 'waimart-basics-rice-5kg', short_description = 'Rice from Waimart Basics.',
                specifications = '{\"Brand\":\"Waimart Basics\"}' WHERE id = ?", [$v['product_id']]);
            $o = $this->makeOrder($v['variant_id']);
            $this->db->exec("UPDATE order_items SET product_name = 'Waimart Basics Rice 5kg' WHERE order_id = ?", [$o['order_id']]);
            $this->db->insert('notifications', ['channel' => 'sms', 'template' => 'x', 'recipient' => '254700000000', 'body' => 'Waimart: sent earlier', 'status' => 'sent']);

            $this->run037();

            $this->assertSame('Shopname Pickup — Moi Avenue', $this->db->value('SELECT name FROM pickup_points WHERE id = ?', [$point]));
            $p = $this->db->one('SELECT name, slug, short_description, specifications FROM products WHERE id = ?', [$v['product_id']]);
            $this->assertSame('Shopname Basics Rice 5kg', $p['name']);
            $this->assertSame('Rice from Shopname Basics.', $p['short_description']);
            $this->assertSame('{"Brand":"Shopname Basics"}', $p['specifications'], 'still valid JSON');
            $this->assertSame('waimart-basics-rice-5kg', $p['slug'], 'web addresses unchanged');
            $this->assertSame('Shopname Basics Rice 5kg', $this->db->value('SELECT product_name FROM order_items WHERE order_id = ?', [$o['order_id']]));
            $this->assertSame('Waimart: sent earlier', $this->db->value("SELECT body FROM notifications WHERE template = 'x'"), 'the message log is history');

            $this->run037();   // running it again changes nothing
            $this->assertSame('Shopname Pickup — Moi Avenue', $this->db->value('SELECT name FROM pickup_points WHERE id = ?', [$point]));
        } finally {
            Settings::set('store_name', (string) $saved);
        }
    }

    public function testItWaitsForDatabaseUpdatesInsteadOfRunningWithAWebsiteUpdate(): void
    {
        $statements = UpdateSqlGuard::analyse((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/037_rename_demo_names.sql'));
        $this->assertContains(false, array_column($statements, 'ok'), 'it changes existing rows, so the admin runs it from Database updates');
    }
}
