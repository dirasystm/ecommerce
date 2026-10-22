<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;
use Waimart\Repositories\CatalogueRepository;
use Waimart\Services\AuditLogger;
use Waimart\Services\Brand;

/**
 * Campaigns and promotional links (Marketing → Link studio).
 *
 * A link has a unique code (/go/{code}) and always points to a page of this shop, built from a validated destination
 * (product, category, brand, store, search, homepage or a shop page), so it can never redirect anywhere else.
 * Advertisement creatives use the product's real name, price, image, rating and seller from the database; the admin
 * can change the wording, never the facts. Clicks are visits (bots and repeat clicks are counted separately).
 */
final class CampaignService
{
    public const OBJECTIVES = ['sales' => 'Sales', 'traffic' => 'Website visits', 'leads' => 'Sign-ups (leads)', 'awareness' => 'Awareness', 'reengagement' => 'Win back customers'];
    public const CHANNELS = ['social' => 'Social media', 'messaging' => 'Messaging (WhatsApp, Telegram)', 'email' => 'Email', 'sms' => 'SMS',
                             'offline' => 'Offline (posters, flyers, QR)', 'partner' => 'Partner or influencer', 'other' => 'Other'];
    public const PLATFORMS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'x' => 'X (Twitter)', 'tiktok' => 'TikTok',
                              'telegram' => 'Telegram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'google' => 'Google', 'other' => 'Other'];
    public const DESTINATIONS = ['product' => 'A product', 'category' => 'A category', 'brand' => 'A brand', 'store' => 'A seller store',
                                 'search' => 'Search results', 'home' => 'Homepage', 'page' => 'Another page of this shop'];
    /** User agents of link-preview crawlers and bots (they get the share preview and are not counted as visits). */
    public const BOT_UA = '/bot|crawl|spider|slurp|facebookexternalhit|facebot|whatsapp|telegram|twitterbot|linkedinbot|discord|slack|pinterest|embedly|preview|curl|wget|python|headless|lighthouse/i';
    public const PREVIEW_UA = '/facebookexternalhit|facebot|whatsapp|telegram|twitterbot|linkedinbot|discord|slack|pinterest|skype|embedly/i';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    // ------------------------------------------------------------------ campaigns

    /** @param array<string,mixed> $in */
    public function saveCampaign(?int $id, array $in, int $actorId): int
    {
        Validator::validate($in, [
            'name' => 'required|max:120', 'objective' => 'required|in:' . implode(',', array_keys(self::OBJECTIVES)),
            'channel' => 'required|in:' . implode(',', array_keys(self::CHANNELS)), 'platform' => 'nullable|in:' . implode(',', array_keys(self::PLATFORMS)),
            'segment' => 'nullable|max:40', 'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date', 'cost' => 'nullable|decimal', 'notes' => 'nullable|max:1000',
        ], ['ends_on' => 'End date', 'starts_on' => 'Start date']);
        if (($in['starts_on'] ?? '') !== '' && ($in['ends_on'] ?? '') !== '' && $in['ends_on'] < $in['starts_on']) {
            throw new ValidationException(['ends_on' => 'The end date must be on or after the start date.']);
        }
        $data = [
            'name' => trim((string) $in['name']), 'objective' => (string) $in['objective'], 'channel' => (string) $in['channel'],
            'platform' => ($in['platform'] ?? '') ?: null, 'segment' => trim((string) ($in['segment'] ?? '')) ?: null,
            'starts_on' => ($in['starts_on'] ?? '') ?: null, 'ends_on' => ($in['ends_on'] ?? '') ?: null,
            'cost' => ($in['cost'] ?? '') === '' ? null : Money::normalize((string) $in['cost']), 'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
        ];
        if ($id === null) {
            $id = $this->db->insert('marketing_campaigns', $data + ['status' => 'active', 'created_by' => $actorId]);
            AuditLogger::log('marketing.campaign_created', 'campaign', $id, null, ['name' => $data['name']], $actorId);
        } else {
            $before = $this->db->one('SELECT name, cost, status FROM marketing_campaigns WHERE id = ?', [$id]);
            $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($data)));
            $this->db->exec("UPDATE marketing_campaigns SET $sets WHERE id = ?", [...array_values($data), $id]);
            AuditLogger::log('marketing.campaign_updated', 'campaign', $id, $before, ['name' => $data['name'], 'cost' => $data['cost']], $actorId);
        }
        return $id;
    }

    public function setCampaignStatus(int $id, string $status, int $actorId): void
    {
        if (!in_array($status, ['active', 'paused', 'ended'], true)) {
            throw ValidationException::withMessage('Unknown status.');
        }
        $this->db->exec('UPDATE marketing_campaigns SET status = ? WHERE id = ?', [$status, $id]);
        AuditLogger::log('marketing.campaign_' . $status, 'campaign', $id, null, null, $actorId);
    }

    // ------------------------------------------------------------------ links

    /**
     * Creates a promotional link (and its creative wording) in a campaign.
     * @param array<string,mixed> $in destination_type, destination (id or slug or text), headline?, description?, cta?, variant?, coupon_code?, utm_source?, utm_medium?, expires_at?
     */
    public function createLink(int $campaignId, array $in, int $actorId): int
    {
        $campaign = $this->db->one('SELECT * FROM marketing_campaigns WHERE id = ?', [$campaignId]) ?? throw ValidationException::withMessage('Campaign not found.');
        Validator::validate($in, ['destination_type' => 'required|in:' . implode(',', array_keys(self::DESTINATIONS)),
            'headline' => 'nullable|max:120', 'description' => 'nullable|max:500', 'cta' => 'nullable|max:40', 'variant' => 'nullable|max:20',
            'coupon_code' => 'nullable|max:40', 'utm_source' => 'nullable|max:40', 'utm_medium' => 'nullable|max:40', 'expires_at' => 'nullable|date'],
            ['destination_type' => 'Destination', 'cta' => 'Button text']);
        [$path, $destId, $facts] = $this->destination((string) $in['destination_type'], (string) ($in['destination'] ?? ''));
        $coupon = strtoupper(trim((string) ($in['coupon_code'] ?? '')));
        if ($coupon !== '' && !$this->db->value('SELECT id FROM coupons WHERE code = ? AND is_active = 1 AND (ends_at IS NULL OR ends_at > NOW())', [$coupon])) {
            throw new ValidationException(['coupon_code' => 'Use an active coupon code from Marketing → Coupons (or leave blank). Discounts are never invented.']);
        }
        $slug = fn ($v) => trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) $v)), '-');
        $id = $this->db->insert('campaign_links', [
            'campaign_id' => $campaignId, 'code' => $this->newCode(), 'variant' => trim((string) ($in['variant'] ?? '')) ?: 'A',
            'destination_type' => (string) $in['destination_type'], 'destination_id' => $destId, 'destination_path' => $path,
            'headline' => mb_substr(trim((string) ($in['headline'] ?? '')) ?: $facts['headline'], 0, 120),
            'description' => mb_substr(trim((string) ($in['description'] ?? '')) ?: $facts['description'], 0, 500) ?: null,
            'cta' => trim((string) ($in['cta'] ?? '')) ?: 'Shop now', 'image_path' => $facts['image'],
            'coupon_code' => $coupon ?: null,
            'utm_source' => $slug($in['utm_source'] ?? '') ?: ($campaign['platform'] ?: $campaign['channel']),
            'utm_medium' => $slug($in['utm_medium'] ?? '') ?: $campaign['channel'],
            'expires_at' => ($in['expires_at'] ?? '') ? date('Y-m-d 23:59:59', strtotime((string) $in['expires_at'])) : null,
            'created_by' => $actorId,
        ]);
        AuditLogger::log('marketing.link_created', 'campaign_link', $id, null, ['campaign' => $campaignId, 'path' => $path], $actorId);
        return $id;
    }

    /** Edits the wording of a link's creative (never its destination, so past results stay meaningful). @param array<string,mixed> $in */
    public function updateCreative(int $linkId, array $in, int $actorId): void
    {
        Validator::validate($in, ['headline' => 'required|max:120', 'description' => 'nullable|max:500', 'cta' => 'required|max:40']);
        $this->db->exec('UPDATE campaign_links SET headline = ?, description = ?, cta = ? WHERE id = ?',
            [trim((string) $in['headline']), trim((string) ($in['description'] ?? '')) ?: null, trim((string) $in['cta']), $linkId]);
        AuditLogger::log('marketing.link_updated', 'campaign_link', $linkId, null, null, $actorId);
    }

    public function setLinkActive(int $linkId, bool $active, int $actorId): void
    {
        $this->db->exec('UPDATE campaign_links SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $linkId]);
        AuditLogger::log($active ? 'marketing.link_enabled' : 'marketing.link_disabled', 'campaign_link', $linkId, null, null, $actorId);
    }

    /**
     * Turns the chosen destination into an internal path, with the facts a creative may use.
     * @return array{0:string,1:?int,2:array{headline:string,description:string,image:?string}}
     */
    public function destination(string $type, string $value): array
    {
        $value = trim($value);
        $none = ['headline' => brand_name(), 'description' => Brand::tagline(), 'image' => null];
        switch ($type) {
            case 'product':
                $p = $this->db->one('SELECT p.id, p.slug, p.name, p.short_description FROM products p ' . CatalogueRepository::SELLER_JOIN
                    . ' WHERE (p.id = ? OR p.slug = ?) AND ' . CatalogueRepository::PUBLIC_PRODUCT, [ctype_digit($value) ? (int) $value : 0, $value]);
                if ($p === null) {
                    throw new ValidationException(['destination' => 'Choose a product that is on sale.']);
                }
                $img = $this->db->value('SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT 1', [$p['id']]);
                return ['/product/' . $p['slug'], (int) $p['id'], ['headline' => (string) $p['name'], 'description' => (string) ($p['short_description'] ?? ''), 'image' => $img ?: null]];
            case 'category':
            case 'brand':
                $t = $type === 'category' ? 'categories' : 'brands';
                $r = $this->db->one("SELECT id, slug, name FROM $t WHERE (id = ? OR slug = ?) AND is_active = 1", [ctype_digit($value) ? (int) $value : 0, $value]);
                if ($r === null) {
                    throw new ValidationException(['destination' => 'Choose an active ' . $type . '.']);
                }
                return ['/' . $type . '/' . $r['slug'], (int) $r['id'], ['headline' => 'Shop ' . $r['name'], 'description' => 'Shop ' . $r['name'] . ' at ' . brand_name() . '.', 'image' => null]];
            case 'store':
                $s = $this->db->one("SELECT id, slug, name FROM sellers WHERE (id = ? OR slug = ?) AND status = 'active'", [ctype_digit($value) ? (int) $value : 0, $value]);
                if ($s === null) {
                    throw new ValidationException(['destination' => 'Choose an active seller store.']);
                }
                return ['/store/' . $s['slug'], (int) $s['id'], ['headline' => $s['name'] . ' on ' . brand_name(), 'description' => 'Shop ' . $s['name'] . ' at ' . brand_name() . '.', 'image' => null]];
            case 'search':
                if ($value === '' || mb_strlen($value) > 80) {
                    throw new ValidationException(['destination' => 'Enter the search words (up to 80 characters).']);
                }
                return ['/search?q=' . rawurlencode($value), null, ['headline' => 'Shop ' . $value, 'description' => 'Find ' . $value . ' at ' . brand_name() . '.', 'image' => null]];
            case 'home':
                return ['/', null, $none];
            case 'page':
                // Only paths of this shop: one leading slash, no scheme, no "//", no backslashes, ordinary characters.
                if (!preg_match('#^/(?!/)[A-Za-z0-9/_.\-]*(\?[A-Za-z0-9_.\-=&%+]*)?$#', $value) || str_contains($value, '..') || mb_strlen($value) > 200) {
                    throw new ValidationException(['destination' => 'Enter a page of this shop starting with /, for example /flash-sales.']);
                }
                return [$value, null, $none];
        }
        throw new ValidationException(['destination_type' => 'Choose where the link goes.']);
    }

    /** The link for a code, and whether it may be used now. @return array{link:?array<string,mixed>,live:bool} */
    public function resolve(string $code): array
    {
        $link = preg_match('/^[A-Za-z0-9]{6,12}$/', $code) ? $this->db->one(
            'SELECT l.*, c.status AS campaign_status, c.starts_on, c.ends_on, c.name AS campaign_name FROM campaign_links l JOIN marketing_campaigns c ON c.id = l.campaign_id WHERE l.code = ?',
            [$code]) : null;
        if ($link === null) {
            return ['link' => null, 'live' => false];
        }
        $today = date('Y-m-d');
        $live = (int) $link['is_active'] === 1 && $link['campaign_status'] === 'active'
            && ($link['expires_at'] === null || strtotime((string) $link['expires_at']) > time())
            && ($link['starts_on'] === null || $link['starts_on'] <= $today) && ($link['ends_on'] === null || $link['ends_on'] >= $today);
        return ['link' => $link, 'live' => $live];
    }

    /** Records a click. Returns whether it was counted as a human visit. */
    public function click(array $link, string $ua, string $referer, string $via = 'link'): bool
    {
        $bot = $ua === '' || (bool) preg_match(self::BOT_UA, $ua);
        $visitor = $bot ? null : Tracker::visitorId(true);
        $unique = !$bot && $visitor !== null && !$this->db->value(
            'SELECT id FROM campaign_clicks WHERE link_id = ? AND visitor_id = ? AND occurred_at > NOW() - INTERVAL 30 DAY LIMIT 1', [$link['id'], $visitor]);
        $host = (string) parse_url($referer, PHP_URL_HOST);
        $this->db->insert('campaign_clicks', ['link_id' => $link['id'], 'visitor_id' => $visitor, 'user_id' => $bot ? null : \Waimart\Core\Auth::id(),
            'is_unique' => $unique ? 1 : 0, 'is_bot' => $bot ? 1 : 0, 'via' => $via === 'qr' ? 'qr' : 'link', 'referrer_host' => $host !== '' ? mb_substr($host, 0, 100) : null]);
        if (!$bot) {
            Attribution::touch((int) $link['id']);
            Tracker::record('link_clicked', ['link_id' => (int) $link['id'], 'first_link_id' => Attribution::current()['first']]);
        }
        return !$bot;
    }

    /** Where a visitor lands: the internal path with campaign tags (no personal data). */
    public static function landingUrl(array $link): string
    {
        $sep = str_contains((string) $link['destination_path'], '?') ? '&' : '?';
        return url((string) $link['destination_path']) . $sep . http_build_query(array_filter([
            'utm_source' => $link['utm_source'], 'utm_medium' => $link['utm_medium'], 'utm_campaign' => $link['code'],
            'utm_content' => $link['variant'] !== 'A' ? $link['variant'] : null]));
    }

    public static function shortUrl(array $link, bool $qr = false): string
    {
        return rtrim((string) config('app.url'), '/') . '/go/' . $link['code'] . ($qr ? '?via=qr' : '');
    }

    /**
     * Everything for the creative and the share texts, from current database facts.
     * @return array<string,mixed>
     */
    public function creative(array $link): array
    {
        $facts = ['name' => null, 'price' => null, 'was' => null, 'discount' => 0, 'rating' => 0.0, 'reviews' => 0, 'seller' => null,
                  'available' => true, 'category' => null, 'brand' => null];
        if ($link['destination_type'] === 'product' && $link['destination_id']) {
            $card = (new CatalogueRepository($this->db))->cards('p.id = ?', [(int) $link['destination_id']], 'p.id', 1)[0] ?? null;
            $meta = $this->db->one('SELECT c.name AS category, b.name AS brand FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id WHERE p.id = ?', [$link['destination_id']]);
            if ($card === null) {
                $facts['available'] = false;        // removed or no longer on sale: the creative is shown as unavailable
            } else {
                $facts = ['name' => $card['name'], 'price' => $card['price'], 'was' => $card['was'], 'discount' => (int) $card['discount_percent'],
                    'rating' => (float) $card['rating'], 'reviews' => (int) $card['rating_count'], 'seller' => $card['seller_is_platform'] ? null : $card['seller_name'],
                    'available' => $card['available'] > 0, 'category' => $meta['category'] ?? null, 'brand' => $meta['brand'] ?? null];
            }
        }
        $image = $link['image_path'] ? media((string) $link['image_path']) : Brand::asset('brand_og_image');
        $absImage = str_starts_with($image, 'http') ? $image : rtrim((string) config('app.url'), '/') . substr($image, strlen(rtrim((string) config('app.base_path', ''), '/')));
        $url = self::shortUrl($link);
        $tags = array_values(array_unique(array_filter(array_map(fn ($t) => '#' . preg_replace('/[^A-Za-z0-9]/', '', ucwords((string) $t)),
            [Brand::name(), $facts['category'], $facts['brand'], 'Kenya', 'ShopOnline', $facts['discount'] > 0 ? 'Deals' : null]), fn ($t) => strlen($t) > 2)));
        $price = $facts['price'] !== null ? Money::format((string) $facts['price']) . ($facts['was'] !== null ? ' (was ' . Money::format((string) $facts['was']) . ')' : '') : null;
        $body = trim((string) $link['description']);
        $lines = array_filter([$link['headline'], $body, $price !== null ? 'Price: ' . $price : null,
            $link['coupon_code'] ? 'Use code ' . $link['coupon_code'] . ' at checkout.' : null]);
        $short = $link['headline'] . ($price !== null ? ' – ' . Money::format((string) $facts['price']) : '');
        return [
            'facts' => $facts, 'image' => $image, 'image_abs' => $absImage, 'url' => $url, 'qr_url' => self::shortUrl($link, true), 'hashtags' => $tags,
            'texts' => [
                'facebook' => implode("\n\n", $lines) . "\n\n" . $link['cta'] . ': ' . $url . "\n\n" . implode(' ', $tags),
                'instagram' => implode("\n\n", $lines) . "\n\n" . $link['cta'] . ': link in bio (' . $url . ")\n\n" . implode(' ', $tags),
                'whatsapp' => '*' . $link['headline'] . "*\n" . ($body !== '' ? $body . "\n" : '') . ($price !== null ? 'Price: ' . $price . "\n" : '')
                    . ($link['coupon_code'] ? 'Code: ' . $link['coupon_code'] . "\n" : '') . "\n" . $link['cta'] . ': ' . $url,
                'x' => mb_strimwidth($short, 0, 180, '…') . ' ' . $url . ' ' . implode(' ', array_slice($tags, 0, 3)),
            ],
            'share' => [
                'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url),
                'whatsapp' => 'https://wa.me/?text=' . rawurlencode($link['headline'] . ' ' . $url),
                'x' => 'https://twitter.com/intent/tweet?text=' . rawurlencode(mb_strimwidth($short, 0, 180, '…')) . '&url=' . rawurlencode($url),
                'telegram' => 'https://t.me/share/url?url=' . rawurlencode($url) . '&text=' . rawurlencode($link['headline']),
                'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($url),
            ],
        ];
    }

    private function newCode(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->db->value('SELECT id FROM campaign_links WHERE code = ?', [$code]));
        return $code;
    }
}
