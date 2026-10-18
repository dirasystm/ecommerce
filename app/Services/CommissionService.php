<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Logger;

/**
 * Marketplace commission rates. Precedence: seller rule > category rule (the product's own category, then its
 * parent) > default rule. The rate found here is SNAPSHOTTED on each order item at order time, so later rule
 * changes never alter past orders.
 */
final class CommissionService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return list<array<string,mixed>> active rules first, then retired ones (history) */
    public function rules(bool $activeOnly = false): array
    {
        return $this->db->all(
            'SELECT r.*, s.name AS seller_name, c.name AS category_name, cu.first_name AS created_first, du.first_name AS deactivated_first
               FROM commission_rules r LEFT JOIN sellers s ON s.id = r.seller_id LEFT JOIN categories c ON c.id = r.category_id
               LEFT JOIN users cu ON cu.id = r.created_by LEFT JOIN users du ON du.id = r.deactivated_by'
            . ($activeOnly ? ' WHERE r.is_active = 1' : '') . "
              ORDER BY r.is_active DESC, FIELD(r.scope, 'default', 'category', 'seller'), r.id DESC LIMIT 500"
        );
    }

    /**
     * Sets the rate for a target. The current rule is retired (kept as history) and a new one added, so past orders
     * — which carry their own snapshot — are never affected.
     */
    public function setRule(string $scope, ?int $targetId, string $rate, ?string $note, int $staffId, bool $confirmProvisional = false): int
    {
        $rate = trim($rate);
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $rate) || bccomp($rate, '100', 2) > 0) {
            throw new \Waimart\Core\ValidationException(['rate' => 'Enter a percentage between 0 and 100 (up to 2 decimals).']);
        }
        if (!in_array($scope, ['default', 'category', 'seller'], true)) {
            throw new \Waimart\Core\ValidationException(['scope' => 'Choose what the rate applies to.']);
        }
        if ($scope !== 'default') {
            $table = $scope === 'seller' ? 'sellers' : 'categories';
            if ($targetId === null || !$this->db->value("SELECT 1 FROM `$table` WHERE id = ?", [$targetId])) {
                throw new \Waimart\Core\ValidationException(['target_id' => 'Choose a ' . ($scope === 'seller' ? 'seller' : 'category') . '.']);
            }
            if ($scope === 'seller' && $targetId === Sellers::platformId($this->db)) {
                throw new \Waimart\Core\ValidationException(['target_id' => 'The Waimart Official Store sells its own stock and pays no commission.']);
            }
        }
        $id = $this->db->transaction(function () use ($scope, $targetId, $rate, $note, $staffId) {
            $col = $scope === 'seller' ? 'seller_id' : ($scope === 'category' ? 'category_id' : null);
            $current = $this->db->one(
                'SELECT * FROM commission_rules WHERE is_active = 1 AND scope = ?' . ($col ? " AND $col = ?" : '') . ' FOR UPDATE',
                $col ? [$scope, $targetId] : [$scope]
            );
            if ($current !== null) {
                $this->db->exec('UPDATE commission_rules SET is_active = 0, deactivated_by = ?, deactivated_at = NOW() WHERE id = ?', [$staffId, $current['id']]);
            }
            $id = $this->db->insert('commission_rules', [
                'scope' => $scope, 'seller_id' => $scope === 'seller' ? $targetId : null, 'category_id' => $scope === 'category' ? $targetId : null,
                'rate' => $rate, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, 'created_by' => $staffId,
            ]);
            AuditLogger::log('commission.rule_set', 'commission_rule', $id, $current ? ['rate' => $current['rate'], 'rule_id' => $current['id']] : null,
                ['scope' => $scope, 'target_id' => $targetId, 'rate' => $rate, 'note' => $note], $staffId);
            return $id;
        });
        if ($scope === 'default' && $confirmProvisional) {
            \Waimart\Core\Settings::set('marketplace_commission_provisional', '0');
            AuditLogger::log('commission.default_confirmed', 'settings', null, null, ['rate' => $rate], $staffId);
        }
        return $id;
    }

    /** Retires a seller or category rule (the default rule can only be replaced, never removed). */
    public function retire(int $ruleId, int $staffId): void
    {
        $r = $this->db->one('SELECT * FROM commission_rules WHERE id = ? AND is_active = 1', [$ruleId])
            ?? throw new \Waimart\Core\ValidationException(['_form' => 'This rule is no longer active.']);
        if ($r['scope'] === 'default') {
            throw \Waimart\Core\ValidationException::withMessage('The default rate cannot be removed — set a new rate instead.');
        }
        if ($r['scope'] === 'seller' && (int) $r['seller_id'] === Sellers::platformId($this->db)) {
            throw \Waimart\Core\ValidationException::withMessage('The Waimart Official Store rate (0%) is fixed: it sells its own stock.');
        }
        $this->db->exec('UPDATE commission_rules SET is_active = 0, deactivated_by = ?, deactivated_at = NOW() WHERE id = ? AND is_active = 1', [$staffId, $ruleId]);
        AuditLogger::log('commission.rule_retired', 'commission_rule', $ruleId, ['rate' => $r['rate'], 'scope' => $r['scope']], null, $staffId);
    }

    /**
     * Commission earned by the marketplace in a period, per seller, from the ledger (money received only).
     * @return list<array<string,mixed>>
     */
    public function report(string $from, string $to): array
    {
        return $this->db->all(
            "SELECT s.id AS seller_id, s.name AS seller_name,
                    SUM(CASE WHEN e.entry_type = 'sale' THEN e.amount ELSE 0 END) AS gross_sales,
                    -SUM(CASE WHEN e.entry_type = 'commission' THEN e.amount ELSE 0 END) AS commission,
                    SUM(CASE WHEN e.entry_type = 'commission_reversal' THEN e.amount ELSE 0 END) AS commission_reversed,
                    SUM(CASE WHEN e.entry_type = 'cancellation' THEN e.amount ELSE 0 END) AS sales_reversed
               FROM seller_ledger_entries e JOIN sellers s ON s.id = e.seller_id
              WHERE e.created_at BETWEEN ? AND ? AND e.entry_type IN ('sale','commission','commission_reversal','cancellation')
              GROUP BY s.id, s.name ORDER BY s.name",
            [$from, $to]
        );
    }

    /**
     * @return array{rate:string,rule_id:?int,scope:string}
     */
    public function rateFor(int $sellerId, int $categoryId): array
    {
        $parent = (int) $this->db->value('SELECT parent_id FROM categories WHERE id = ?', [$categoryId]);
        $rules = $this->db->all(
            "SELECT id, scope, category_id, seller_id, rate FROM commission_rules
              WHERE is_active = 1 AND ((scope = 'seller' AND seller_id = ?) OR (scope = 'category' AND category_id IN (?, ?)) OR scope = 'default')",
            [$sellerId, $categoryId, $parent ?: 0]
        );
        $pick = fn (callable $match) => current(array_filter($rules, $match)) ?: null;
        $rule = $pick(fn ($r) => $r['scope'] === 'seller')
            ?? $pick(fn ($r) => $r['scope'] === 'category' && (int) $r['category_id'] === $categoryId)
            ?? ($parent ? $pick(fn ($r) => $r['scope'] === 'category' && (int) $r['category_id'] === $parent) : null)
            ?? $pick(fn ($r) => $r['scope'] === 'default');
        if ($rule === null) {
            Logger::warning('No active commission rule; charging 0%', ['seller_id' => $sellerId, 'category_id' => $categoryId]);
            return ['rate' => '0.00', 'rule_id' => null, 'scope' => 'none'];
        }
        return ['rate' => (string) $rule['rate'], 'rule_id' => (int) $rule['id'], 'scope' => (string) $rule['scope']];
    }
}
