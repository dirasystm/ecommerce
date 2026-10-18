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
