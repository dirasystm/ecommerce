<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;

/**
 * Inventory rules (C1). All stock quantity changes go through applyChange(), which
 * locks the inventory row, applies the deltas, and writes the stock_movements ledger
 * in the same transaction. Invariant: 0 <= reserved <= on_hand (also enforced by DB CHECKs).
 */
final class InventoryService
{
    public const RECEIPT_REASONS = [
        'purchase'    => 'New stock received from supplier',
        'transfer_in' => 'Transfer in from another location',
        'opening'     => 'Opening stock',
    ];

    public const ADJUST_REASONS = [
        'stock_count' => 'Stock count correction',
        'damaged'     => 'Damaged',
        'lost'        => 'Lost or stolen',
        'expired'     => 'Expired',
        'found'       => 'Found stock',
        'other'       => 'Other',
    ];

    public function __construct(private ?Database $db = null)
    {
        $this->db ??= App::db();
    }

    /** @return array{on_hand:int,reserved:int,available:int} */
    public function levels(int $variantId): array
    {
        $row = $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ?', [$variantId]);
        if ($row === null) {
            throw ValidationException::withMessage('This product variant has no inventory record.');
        }
        return self::shape($row);
    }

    /** Records stock received into the warehouse. */
    public function receive(int $variantId, int $qty, string $reason, ?string $note, int $actorId, string $actorType = 'staff'): array
    {
        if ($qty < 1 || $qty > 100000) {
            throw new ValidationException(['quantity' => 'Enter a quantity between 1 and 100,000.']);
        }
        if (!isset(self::RECEIPT_REASONS[$reason])) {
            throw new ValidationException(['reason' => 'Select a valid reason.']);
        }
        return $this->db->transaction(function () use ($variantId, $qty, $reason, $note, $actorId, $actorType) {
            $before = $this->lock($variantId);
            $after = $this->applyChange($variantId, $qty, 0, 'receipt', [
                'actor_type' => $actorType, 'actor_user_id' => $actorId, 'reason' => $reason, 'note' => $note,
            ]);
            AuditLogger::log('inventory.receive', 'variant', $variantId, $before, $after + ['qty' => $qty, 'reason' => $reason], $actorId);
            return $after;
        });
    }

    /**
     * Manual adjustment by a signed delta. Physical stock can never drop below the units
     * already reserved for open orders — those must be released through the order workflow.
     */
    public function adjust(int $variantId, int $delta, string $reason, ?string $note, int $actorId, string $actorType = 'staff'): array
    {
        if ($delta === 0 || abs($delta) > 100000) {
            throw new ValidationException(['delta' => 'Enter a non-zero adjustment (maximum 100,000).']);
        }
        if (!isset(self::ADJUST_REASONS[$reason])) {
            throw new ValidationException(['reason' => 'Select a valid reason.']);
        }
        if ($reason === 'other' && trim((string) $note) === '') {
            throw new ValidationException(['note' => 'Explain the adjustment when the reason is "Other".']);
        }
        return $this->db->transaction(function () use ($variantId, $delta, $reason, $note, $actorId, $actorType) {
            $before = $this->lock($variantId);
            if ($before['on_hand'] + $delta < $before['reserved']) {
                throw new ValidationException(['delta' => sprintf(
                    'Stock cannot go below %d unit(s) reserved for open orders. Available to adjust: %d.',
                    $before['reserved'],
                    $before['available']
                )]);
            }
            $after = $this->applyChange($variantId, $delta, 0, 'adjustment', [
                'actor_type' => $actorType, 'actor_user_id' => $actorId, 'reason' => $reason, 'note' => $note,
            ]);
            AuditLogger::log('inventory.adjust', 'variant', $variantId, $before, $after + ['delta' => $delta, 'reason' => $reason], $actorId);
            return $after;
        });
    }

    /** Stock count: sets on-hand to the counted figure (as an adjustment). */
    public function setCount(int $variantId, int $counted, ?string $note, int $actorId, string $actorType = 'staff'): array
    {
        if ($counted < 0) {
            throw new ValidationException(['counted' => 'Counted stock cannot be negative.']);
        }
        return $this->db->transaction(function () use ($variantId, $counted, $note, $actorId, $actorType) {
            $current = $this->lock($variantId);
            $delta = $counted - $current['on_hand'];
            if ($delta === 0) {
                return $current;
            }
            return $this->adjust($variantId, $delta, 'stock_count', $note, $actorId, $actorType);
        });
    }

    /**
     * Locks the inventory row (SELECT … FOR UPDATE). Must be called inside a transaction.
     * @return array{on_hand:int,reserved:int,available:int}
     */
    public function lock(int $variantId): array
    {
        $this->assertInTransaction();
        $row = $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ? FOR UPDATE', [$variantId]);
        if ($row === null) {
            throw ValidationException::withMessage('This product variant has no inventory record.');
        }
        return self::shape($row);
    }

    /**
     * Core primitive: applies on-hand / reserved deltas and records exactly one ledger row.
     * Caller must hold the row lock (lock()) inside the current transaction.
     *
     * @param array{actor_type:string,actor_user_id?:?int,reason?:?string,note?:?string,reservation_id?:?int,
     *              return_item_id?:?int,order_id?:?int} $ctx
     * @return array{on_hand:int,reserved:int,available:int}
     */
    public function applyChange(int $variantId, int $onHandDelta, int $reservedDelta, string $type, array $ctx): array
    {
        $this->assertInTransaction();
        $this->db->exec(
            'UPDATE inventory SET on_hand = on_hand + ?, reserved = reserved + ? WHERE variant_id = ?',
            [$onHandDelta, $reservedDelta, $variantId]
        );
        $after = self::shape((array) $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ?', [$variantId]));
        $this->db->insert('stock_movements', [
            'variant_id'     => $variantId,
            'movement_type'  => $type,
            'on_hand_delta'  => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'on_hand_after'  => $after['on_hand'],
            'reserved_after' => $after['reserved'],
            'reservation_id' => $ctx['reservation_id'] ?? null,
            'return_item_id' => $ctx['return_item_id'] ?? null,
            'order_id'       => $ctx['order_id'] ?? null,
            'actor_type'     => $ctx['actor_type'],
            'actor_user_id'  => $ctx['actor_user_id'] ?? null,
            'reason'         => $ctx['reason'] ?? null,
            'note'           => isset($ctx['note']) && $ctx['note'] !== '' ? mb_substr((string) $ctx['note'], 0, 255) : null,
        ]);
        return $after;
    }

    /** @param array<string,mixed> $row */
    private static function shape(array $row): array
    {
        $onHand = (int) $row['on_hand'];
        $reserved = (int) $row['reserved'];
        return ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
    }

    private function assertInTransaction(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Inventory changes must run inside a transaction.');
        }
    }
}
