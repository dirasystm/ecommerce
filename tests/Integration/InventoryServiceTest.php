<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\ValidationException;
use Waimart\Services\InventoryService;

final class InventoryServiceTest extends DbTestCase
{
    private InventoryService $inv;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv = new InventoryService($this->db);
        $this->staff = $this->db->insert('users', ['email' => 'clerk@x.co', 'password_hash' => 'x', 'first_name' => 'C', 'last_name' => 'K', 'is_staff' => 1]);
    }

    public function testReceiveIncreasesOnHandAndWritesLedgerAndAudit(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $after = $this->inv->receive($v, 7, 'purchase', 'INV-001', $this->staff);
        $this->assertSame(['on_hand' => 12, 'reserved' => 0, 'available' => 12], $after);

        $m = $this->db->one('SELECT * FROM stock_movements WHERE variant_id = ? ORDER BY id DESC LIMIT 1', [$v]);
        $this->assertSame('receipt', $m['movement_type']);
        $this->assertSame(7, (int) $m['on_hand_delta']);
        $this->assertSame(12, (int) $m['on_hand_after']);
        $this->assertSame('purchase', $m['reason']);
        $this->assertSame($this->staff, (int) $m['actor_user_id']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'inventory.receive' AND entity_id = ?", [$v]));
    }

    public function testAdjustCannotGoBelowReservedUnits(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        $this->db->exec('UPDATE inventory SET reserved = 8 WHERE variant_id = ?', [$v]);
        try {
            $this->inv->adjust($v, -3, 'damaged', null, $this->staff);
            $this->fail('Expected rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('8 unit(s) reserved', $e->errors['delta']);
        }
        $this->assertSame(['on_hand' => 10, 'reserved' => 8, 'available' => 2], $this->inv->levels($v));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE variant_id = ? AND movement_type = 'adjustment'", [$v]));

        $this->inv->adjust($v, -2, 'damaged', null, $this->staff);
        $this->assertSame(['on_hand' => 8, 'reserved' => 8, 'available' => 0], $this->inv->levels($v));
    }

    public function testAdjustValidation(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        foreach ([[0, 'damaged', null, 'delta'], [1, 'bogus', null, 'reason'], [1, 'other', '', 'note']] as [$d, $r, $n, $field]) {
            try {
                $this->inv->adjust($v, $d, $r, $n, $this->staff);
                $this->fail("Expected error on $field");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors);
            }
        }
    }

    public function testStockCountSetsOnHandAndRecordsDifference(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        $after = $this->inv->setCount($v, 7, 'Monthly count', $this->staff);
        $this->assertSame(7, $after['on_hand']);
        $m = $this->db->one("SELECT * FROM stock_movements WHERE variant_id = ? AND movement_type = 'adjustment'", [$v]);
        $this->assertSame(-3, (int) $m['on_hand_delta']);
        $this->assertSame('stock_count', $m['reason']);
        // Same count again is a no-op (no extra ledger row).
        $this->inv->setCount($v, 7, null, $this->staff);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE variant_id = ? AND movement_type = 'adjustment'", [$v]));
    }

    public function testLowLevelPrimitivesRequireTransaction(): void
    {
        $v = $this->makeVariant(1)['variant_id'];
        $this->expectException(\LogicException::class);
        $this->inv->applyChange($v, 1, 0, 'receipt', ['actor_type' => 'system']);
    }

    public function testLedgerReconcilesWithInventory(): void
    {
        $v = $this->makeVariant(0)['variant_id'];
        $this->inv->receive($v, 20, 'purchase', null, $this->staff);
        $this->inv->adjust($v, -4, 'damaged', null, $this->staff);
        $this->inv->receive($v, 3, 'transfer_in', null, $this->staff);
        $sum = (int) $this->db->value('SELECT SUM(on_hand_delta) FROM stock_movements WHERE variant_id = ?', [$v]);
        $this->assertSame($this->inv->levels($v)['on_hand'], $sum);
    }
}
