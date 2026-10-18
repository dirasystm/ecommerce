<?php

declare(strict_types=1);

namespace Waimart\Repositories;

use Waimart\Core\Database;

/**
 * Public store pages. Only fields meant for customers leave this class: never legal name, business address,
 * documents, payout details, team members, suspension reasons or internal figures.
 */
final class StoreRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null an ACTIVE store's public profile */
    public function findPublic(string $slug): ?array
    {
        $s = $this->db->one(
            "SELECT s.id, s.name, s.slug, s.description, s.logo_path, s.is_platform, s.is_verified, s.town, s.approved_at, s.created_at,
                    s.show_contacts, s.public_email, s.public_phone, c.name AS county_name
               FROM sellers s LEFT JOIN counties c ON c.id = s.county_id
              WHERE s.slug = ? AND s.status = 'active'",
            [$slug]
        );
        if ($s === null) {
            return null;
        }
        if (!(int) $s['show_contacts']) {
            $s['public_email'] = null;
            $s['public_phone'] = null;
        }
        unset($s['show_contacts']);
        return $s + ['rating' => $this->rating((int) $s['id'])];
    }

    /**
     * Store rating from APPROVED reviews of verified purchases of this store's products.
     * @return array{avg:float,count:int}
     */
    public function rating(int $sellerId): array
    {
        $r = $this->db->one(
            "SELECT AVG(r.rating) AS avg, COUNT(*) AS n FROM reviews r JOIN products p ON p.id = r.product_id
              WHERE p.seller_id = ? AND r.status = 'approved'",
            [$sellerId]
        );
        return ['avg' => round((float) ($r['avg'] ?? 0), 1), 'count' => (int) ($r['n'] ?? 0)];
    }

    /** @return list<array{id:int,name:string,n:int}> categories that have products visible in this store */
    public function categories(int $sellerId): array
    {
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'n' => (int) $r['n']], $this->db->all(
            'SELECT c.id, c.name, COUNT(*) AS n FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
               ' . CatalogueRepository::SELLER_JOIN . '
              WHERE p.seller_id = ? AND ' . CatalogueRepository::PUBLIC_PRODUCT . '
              GROUP BY c.id, c.name ORDER BY c.name',
            [$sellerId]
        ));
    }
}
