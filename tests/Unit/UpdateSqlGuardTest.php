<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Services\UpdateSqlGuard;

/** Live updates may only add to the database: existing data and the running code must not be affected. */
final class UpdateSqlGuardTest extends TestCase
{
    private function verdict(string $sql): array
    {
        return UpdateSqlGuard::statement($sql);
    }

    public function testAdditiveChangesAreAllowedAndKnowHowToBeUndone(): void
    {
        $t = $this->verdict("CREATE TABLE IF NOT EXISTS `gift_cards` (\n id INT PRIMARY KEY, code VARCHAR(20) NOT NULL\n) ENGINE=InnoDB");
        $this->assertTrue($t['ok']);
        $this->assertSame([['type' => 'table', 'table' => 'gift_cards', 'name' => 'gift_cards']], $t['objects']);
        $this->assertSame(['DROP TABLE IF EXISTS `gift_cards`'], UpdateSqlGuard::undo($t['objects'][0]));

        $a = $this->verdict('ALTER TABLE orders ADD COLUMN gift_card_id INT NULL AFTER coupon_code, ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT \'web\','
            . ' ADD INDEX ix_orders_gift (gift_card_id), ADD CONSTRAINT fk_orders_gift FOREIGN KEY (gift_card_id) REFERENCES gift_cards (id), ALGORITHM=INPLACE, LOCK=NONE');
        $this->assertTrue($a['ok'], (string) $a['reason']);
        $this->assertSame(['column', 'column', 'index', 'foreign_key'], array_column($a['objects'], 'type'));
        $this->assertSame(['ALTER TABLE `orders` DROP FOREIGN KEY IF EXISTS `fk_orders_gift`', 'ALTER TABLE `orders` DROP INDEX IF EXISTS `fk_orders_gift`'],
            UpdateSqlGuard::undo($a['objects'][3]));
        $this->assertSame('ALTER TABLE `orders` DROP COLUMN IF EXISTS `source`', UpdateSqlGuard::undo($a['objects'][1])[0]);

        foreach (["INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('x', '1')",
                  "INSERT INTO permissions (slug, description) SELECT 'a.b', 'c'",
                  'CREATE UNIQUE INDEX ux_a ON products (slug, seller_id)',
                  "CREATE TRIGGER trg_x BEFORE DELETE ON gift_cards FOR EACH ROW SIGNAL SQLSTATE '45000'",
                  'SET FOREIGN_KEY_CHECKS = 0',
                  'ALTER TABLE users ADD COLUMN full_name VARCHAR(200) AS (CONCAT(first_name, \' \', last_name)) VIRTUAL'] as $sql) {
            $this->assertTrue($this->verdict($sql)['ok'], $sql);
        }
    }

    public function testAnythingThatTouchesExistingDataOrStructureIsRefused(): void
    {
        $cases = [
            'UPDATE orders SET status = \'x\'' => 'UPDATE',
            'DELETE FROM users' => 'DELETE',
            'DROP TABLE orders' => 'DROP',
            'TRUNCATE settings' => 'TRUNCATE',
            'RENAME TABLE a TO b' => 'RENAME',
            "REPLACE INTO settings VALUES ('a','b')" => 'REPLACE',
            'ALTER TABLE orders DROP COLUMN notes' => 'Removes something',
            'ALTER TABLE orders MODIFY notes TEXT' => 'Changes an existing column',
            'ALTER TABLE orders CHANGE notes note TEXT' => 'Changes an existing column',
            'ALTER TABLE orders ADD COLUMN x INT, DROP COLUMN notes' => 'Removes something',
            'ALTER TABLE orders ADD COLUMN must_have VARCHAR(10) NOT NULL' => 'has no DEFAULT',
            'ALTER TABLE orders ADD INDEX (status)' => 'Give every new index',
            'ALTER TABLE orders ADD FOREIGN KEY (user_id) REFERENCES users (id)' => 'Give every new constraint',
            'ALTER TABLE orders ADD PRIMARY KEY (id)' => 'primary key',
            'ALTER TABLE orders ENGINE=MyISAM' => 'does not allow',
            'CREATE TABLE copy_orders AS SELECT * FROM orders' => 'copies data',
            'CREATE TABLE copy_orders (id INT) SELECT id FROM orders' => 'copies data',
            'CREATE OR REPLACE VIEW v AS SELECT 1' => 'Only new tables',
            'GRANT ALL ON *.* TO x' => 'GRANT',
        ];
        foreach ($cases as $sql => $needle) {
            $v = $this->verdict($sql);
            $this->assertFalse($v['ok'], $sql);
            $this->assertStringContainsString($needle, (string) $v['reason'], $sql);
        }
    }

    public function testTheProjectsOwnMigrationsAreClassifiedSensibly(): void
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations';
        $verdicts = [];
        foreach (['022_content_management.sql', '023_payments_delivery_messages.sql', '024_roles_mfa_security.sql'] as $f) {
            $refused = array_values(array_filter(UpdateSqlGuard::analyse((string) file_get_contents("$dir/$f")), fn ($v) => !$v['ok']));
            $verdicts[$f] = array_map(fn ($v) => strtoupper((string) strtok(trim($v['sql']), ' ')), $refused);
        }
        $this->assertSame([], $verdicts['022_content_management.sql'], 'content tables + seed rows: additive');
        $this->assertSame([], $verdicts['023_payments_delivery_messages.sql'], 'new tables, settings and permissions: additive');
        $this->assertSame(['UPDATE'], $verdicts['024_roles_mfa_security.sql'], 'marking the shipped roles built-in changes existing rows: needs bin/migrate.php');
    }
}
