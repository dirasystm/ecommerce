<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\HttpException;
use Waimart\Core\ValidationException;

/**
 * Editable site content (Admin → Content, permission content.manage):
 *  - pages: information and legal pages, with publish/unpublish times and a revision kept on every save;
 *  - menus: the fixed places on the site that list links or short items (header, footer, homepage tiles, phone bar);
 *  - homepage sections: what the homepage shows, in order, each can be hidden or scheduled.
 * All writes are validated and audited. Text is rendered by ContentRenderer (safe format, no raw HTML).
 */
final class SiteContentService
{
    /** Built-in pages: fixed addresses (/{slug}), cannot be deleted or renamed. */
    public const SYSTEM_PAGES = ['help', 'how-to-pay', 'delivery-pickup', 'pickup-stations', 'returns-policy', 'contact', 'about', 'terms', 'privacy'];
    /** Menus whose items may have one level of drop-down items. */
    public const DROPDOWN_MENUS = ['topbar_links', 'help_menu'];
    /** Menus whose items must link somewhere. */
    public const LINKED_MENUS = ['topbar_links', 'help_menu', 'footer_col_1', 'footer_col_2', 'footer_col_3', 'home_side_tiles', 'mobile_nav'];
    public const MOBILE_NAV_MAX = 5;
    public const SECTION_TYPES = [
        'hero' => 'Slider, category list and side tiles', 'quick_categories' => 'Popular category icons', 'flash_sale' => 'Flash sale (when one is running)',
        'top_deals' => 'Biggest discounts', 'shop_by_category' => 'Category grid', 'featured' => 'Featured products',
        'category_rails' => 'A row of products for each top category', 'category_row' => 'Products from one category', 'values' => '"Why shop with us" strip',
        'text' => 'Text block',
        'recommended' => 'Recommended for you (personalised; popular products for new visitors)',
    ];
    /** Section types that can be added more than once (the others exist once and can be hidden). */
    public const REPEATABLE_SECTIONS = ['category_row', 'text'];
    private const MAX_REVISIONS = 30;

    private Database $db;
    /** @var array<string,list<array<string,mixed>>> per-request menu cache */
    private static array $menuCache = [];
    /** @var array<string,list<array<string,mixed>>>|null every visible menu item, by menu slug: one query per request (audit P-2) */
    private static ?array $menuRows = null;
    /** @var array<string,string>|null menu titles by slug, one query per request */
    private static ?array $menuTitles = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    public static function flushCache(): void
    {
        self::$menuCache = [];
        self::$menuRows = null;
        self::$menuTitles = null;
    }

    // ================================================================== pages

    private const LIVE = "status = 'published' AND (publish_at IS NULL OR publish_at <= NOW()) AND (unpublish_at IS NULL OR unpublish_at > NOW())";

    /** @return array<string,mixed>|null a page visitors can see now */
    public function livePage(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM content_pages WHERE slug = ? AND ' . self::LIVE, [$slug]);
    }

    /** @return list<array<string,mixed>> live pages for the Help Center side menu */
    public function navPages(): array
    {
        return $this->db->all('SELECT slug, title FROM content_pages WHERE show_in_nav = 1 AND ' . self::LIVE . ' ORDER BY sort_order, title');
    }

    public static function pageUrl(string $slug): string
    {
        return in_array($slug, self::SYSTEM_PAGES, true) ? '/' . $slug : '/pages/' . $slug;
    }

    /** @return list<array<string,mixed>> */
    public function pages(): array
    {
        return $this->db->all('SELECT p.*, u.first_name AS editor FROM content_pages p LEFT JOIN users u ON u.id = p.updated_by ORDER BY p.is_system DESC, p.sort_order, p.title');
    }

    /** @return array<string,mixed> */
    public function page(int $id): array
    {
        return $this->db->one('SELECT * FROM content_pages WHERE id = ?', [$id]) ?? throw HttpException::notFound();
    }

    /** Creates ($id null) or updates a page; the previous version is kept as a revision. @param array<string,mixed> $in */
    public function savePage(?int $id, array $in, int $actorId): int
    {
        $existing = $id !== null ? $this->page($id) : null;
        $title = trim((string) ($in['title'] ?? ''));
        $meta = trim((string) ($in['meta_description'] ?? ''));
        $body = str_replace("\r\n", "\n", (string) ($in['body'] ?? ''));
        $status = (string) ($in['status'] ?? 'draft');
        $slug = $existing && (int) $existing['is_system'] ? (string) $existing['slug'] : strtolower(trim((string) ($in['slug'] ?? '')));
        $errors = [];
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = 'Enter a title (up to 120 characters).';
        }
        if (mb_strlen($meta) > 200) {
            $errors['meta_description'] = 'Keep the description to 200 characters.';
        }
        if (trim($body) === '' || mb_strlen($body) > 60000) {
            $errors['body'] = 'Write the page content (up to 60,000 characters).';
        }
        if (!in_array($status, ['draft', 'published'], true)) {
            $errors['status'] = 'Choose draft or published.';
        }
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 80) {
            $errors['slug'] = 'Use lowercase letters, numbers and hyphens, e.g. "shipping-policy".';
        } elseif ((!$existing || !(int) $existing['is_system']) && in_array($slug, self::SYSTEM_PAGES, true)) {
            $errors['slug'] = 'That address belongs to a built-in page.';
        } elseif ($this->db->value('SELECT 1 FROM content_pages WHERE slug = ? AND id <> ?', [$slug, $id ?? 0])) {
            $errors['slug'] = 'Another page already uses that address.';
        }
        $publishAt = self::dateTime($in['publish_at'] ?? '', 'publish_at', $errors);
        $unpublishAt = self::dateTime($in['unpublish_at'] ?? '', 'unpublish_at', $errors);
        if ($publishAt && $unpublishAt && $unpublishAt <= $publishAt) {
            $errors['unpublish_at'] = 'The end must be after the start.';
        }
        $sort = (string) ($in['sort_order'] ?? '100');
        if (!preg_match('/^-?\d{1,5}$/', $sort)) {
            $errors['sort_order'] = 'Use a whole number.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $row = ['slug' => $slug, 'title' => $title, 'meta_description' => $meta !== '' ? $meta : null, 'body' => $body, 'status' => $status,
                'publish_at' => $publishAt, 'unpublish_at' => $unpublishAt, 'show_in_nav' => !empty($in['show_in_nav']) ? 1 : 0,
                'sort_order' => (int) $sort, 'updated_by' => $actorId];
        return $this->db->transaction(function () use ($existing, $row, $actorId) {
            if ($existing === null) {
                $id = $this->db->insert('content_pages', $row);
                AuditLogger::log('content.page_created', 'content_page', $id, null, array_diff_key($row, ['body' => 1]), $actorId);
                return $id;
            }
            $id = (int) $existing['id'];
            $this->keepRevision($existing, $actorId);
            $sets = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($row)));
            $this->db->exec("UPDATE content_pages SET $sets WHERE id = ?", [...array_values($row), $id]);
            $changed = array_keys(array_filter($row, fn ($v, $k) => (string) ($existing[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
            AuditLogger::log('content.page_updated', 'content_page', $id, array_intersect_key($existing, array_flip(array_diff($changed, ['body']))),
                array_intersect_key($row, array_flip(array_diff($changed, ['body']))) + (in_array('body', $changed, true) ? ['body' => 'changed'] : []), $actorId);
            return $id;
        });
    }

    public function deletePage(int $id, int $actorId): void
    {
        $p = $this->page($id);
        if ((int) $p['is_system']) {
            throw ValidationException::withMessage('Built-in pages cannot be deleted. You can change them, or set them to draft.');
        }
        $this->db->exec('DELETE FROM content_pages WHERE id = ?', [$id]);
        AuditLogger::log('content.page_deleted', 'content_page', $id, ['slug' => $p['slug'], 'title' => $p['title']], null, $actorId);
    }

    /** @return list<array<string,mixed>> */
    public function revisions(int $pageId): array
    {
        return $this->db->all('SELECT r.id, r.title, r.created_at, CHAR_LENGTH(r.body) AS length, u.first_name, u.last_name FROM content_page_revisions r
                                 LEFT JOIN users u ON u.id = r.created_by WHERE r.page_id = ? ORDER BY r.id DESC', [$pageId]);
    }

    /** Brings back an earlier version (the current one is kept as a revision, so this can be undone too). */
    public function restoreRevision(int $pageId, int $revisionId, int $actorId): void
    {
        $this->db->transaction(function () use ($pageId, $revisionId, $actorId) {
            $p = $this->db->one('SELECT * FROM content_pages WHERE id = ? FOR UPDATE', [$pageId]) ?? throw HttpException::notFound();
            $r = $this->db->one('SELECT * FROM content_page_revisions WHERE id = ? AND page_id = ?', [$revisionId, $pageId]) ?? throw HttpException::notFound();
            $this->keepRevision($p, $actorId);
            $this->db->exec('UPDATE content_pages SET title = ?, meta_description = ?, body = ?, updated_by = ? WHERE id = ?',
                [$r['title'], $r['meta_description'], $r['body'], $actorId, $pageId]);
            AuditLogger::log('content.page_restored', 'content_page', $pageId, null, ['revision' => $revisionId], $actorId);
        });
    }

    /** @param array<string,mixed> $page */
    private function keepRevision(array $page, int $actorId): void
    {
        $this->db->insert('content_page_revisions', ['page_id' => $page['id'], 'title' => $page['title'], 'meta_description' => $page['meta_description'],
            'body' => $page['body'], 'created_by' => $actorId]);
        $keep = $this->db->value('SELECT id FROM content_page_revisions WHERE page_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . (self::MAX_REVISIONS - 1), [$page['id']]);
        if ($keep) {
            $this->db->exec('DELETE FROM content_page_revisions WHERE page_id = ? AND id < ?', [$page['id'], $keep]);
        }
    }

    /** "2026-10-20T09:30" (datetime-local) → "2026-10-20 09:30:00"; empty → null. @param array<string,string> $errors */
    private static function dateTime(mixed $v, string $field, array &$errors): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('!Y-m-d\TH:i', $v) ?: \DateTime::createFromFormat('!Y-m-d H:i:s', $v);
        if (!$d) {
            $errors[$field] = 'Enter a date and time.';
            return null;
        }
        return $d->format('Y-m-d H:i:s');
    }

    // ================================================================== menus

    /**
     * Visible items of a menu with their visible drop-down items, labels filled in ({store}).
     * @return list<array<string,mixed>>
     */
    public function menu(string $slug): array
    {
        if (isset(self::$menuCache[$slug])) {
            return self::$menuCache[$slug];
        }
        if (self::$menuRows === null) {
            // Header, footer and mobile menus appear on every page: load them all at once instead of one query each.
            try {
                self::$menuRows = [];
                foreach ($this->db->all(
                    'SELECT m.slug AS menu_slug, i.* FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE i.is_visible = 1 ORDER BY i.parent_id IS NOT NULL, i.sort_order, i.id'
                ) as $r) {
                    self::$menuRows[(string) $r['menu_slug']][] = $r;
                }
            } catch (\Throwable) {
                self::$menuRows = null;
                return self::$menuCache[$slug] = [];
            }
        }
        $rows = self::$menuRows[$slug] ?? [];
        $top = [];
        foreach ($rows as $r) {
            $r['label'] = brand_text((string) $r['label']);
            $r['description'] = brand_text((string) $r['description']);
            $r['href'] = self::href((string) $r['link']);
            $r['children'] = [];
            if ($r['parent_id'] === null) {
                $top[(int) $r['id']] = $r;
            } elseif (isset($top[(int) $r['parent_id']])) {
                $top[(int) $r['parent_id']]['children'][] = $r;
            }
        }
        $items = array_values($top);
        if ($slug === 'mobile_nav') {
            $items = array_slice($items, 0, self::MOBILE_NAV_MAX);
        }
        return self::$menuCache[$slug] = $items;
    }

    /** A menu's own title (e.g. a footer column heading), {store} filled in. */
    public function menuTitle(string $slug): string
    {
        if (self::$menuTitles === null) {
            try {
                self::$menuTitles = array_column($this->db->all('SELECT slug, title FROM menus'), 'title', 'slug');
            } catch (\Throwable) {
                return '';
            }
        }
        return brand_text((string) (self::$menuTitles[$slug] ?? ''));
    }

    /** Public URL for a stored link (internal paths go through url()); '' when none or unsafe. */
    public static function href(string $link): string
    {
        if ($link === '' || !ContentRenderer::safeLink($link)) {
            return '';
        }
        return str_starts_with($link, '/') ? url($link) : $link;
    }

    /** @return list<array<string,mixed>> */
    public function menus(): array
    {
        return $this->db->all('SELECT m.*, (SELECT COUNT(*) FROM menu_items i WHERE i.menu_id = m.id) AS items FROM menus m ORDER BY m.id');
    }

    /** @return array<string,mixed> */
    public function menuBySlug(string $slug): array
    {
        return $this->db->one('SELECT * FROM menus WHERE slug = ?', [$slug]) ?? throw HttpException::notFound();
    }

    /** All items (including hidden), top level then their children. @return list<array<string,mixed>> */
    public function menuItems(int $menuId): array
    {
        $rows = $this->db->all('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order, id', [$menuId]);
        $out = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] === null) {
                $out[] = $r + ['depth' => 0];
                foreach ($rows as $c) {
                    if ((int) $c['parent_id'] === (int) $r['id']) {
                        $out[] = $c + ['depth' => 1];
                    }
                }
            }
        }
        return $out;
    }

    public function saveMenuTitle(string $slug, string $title, int $actorId): void
    {
        $m = $this->menuBySlug($slug);
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new ValidationException(['menu_title' => 'Enter a heading (up to 80 characters).']);
        }
        $this->db->exec('UPDATE menus SET title = ? WHERE id = ?', [$title, $m['id']]);
        AuditLogger::log('content.menu_renamed', 'menu', (int) $m['id'], ['title' => $m['title']], ['title' => $title], $actorId);
        self::flushCache();
    }

    /** Creates ($itemId null) or updates a menu item. @param array<string,mixed> $in */
    public function saveMenuItem(string $menuSlug, ?int $itemId, array $in, int $actorId): int
    {
        $m = $this->menuBySlug($menuSlug);
        $existing = $itemId !== null ? $this->item((int) $m['id'], $itemId) : null;
        $label = trim((string) ($in['label'] ?? ''));
        $link = trim((string) ($in['link'] ?? ''));
        $icon = strtolower(trim((string) ($in['icon'] ?? '')));
        $desc = trim((string) ($in['description'] ?? ''));
        $parent = (string) ($in['parent_id'] ?? '') !== '' ? (int) $in['parent_id'] : null;
        $errors = [];
        if ($label === '' || mb_strlen($label) > 80) {
            $errors['label'] = 'Enter a label (up to 80 characters).';
        }
        if ($link !== '' && !ContentRenderer::safeLink($link)) {
            $errors['link'] = 'Use a page on this site starting with / (e.g. /help), a full https:// address, mailto: or tel:.';
        }
        if ($link === '' && in_array($menuSlug, self::LINKED_MENUS, true)) {
            $errors['link'] = 'Items in this menu need a link.';
        }
        if ($icon !== '' && !preg_match('/^[a-z0-9-]{1,40}$/', $icon)) {
            $errors['icon'] = 'Use an icon name from the list, e.g. truck or shop.';
        }
        if (mb_strlen($desc) > 160) {
            $errors['description'] = 'Keep the short text to 160 characters.';
        }
        if ($parent !== null) {
            $p = $this->db->one('SELECT * FROM menu_items WHERE id = ? AND menu_id = ? AND parent_id IS NULL', [$parent, $m['id']]);
            if (!in_array($menuSlug, self::DROPDOWN_MENUS, true) || $p === null || $parent === $itemId
                || ($existing && $this->db->value('SELECT 1 FROM menu_items WHERE parent_id = ?', [$itemId]))) {
                $errors['parent_id'] = 'Choose a top-level item of this menu (drop-downs are one level deep).';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $row = ['label' => $label, 'link' => $link !== '' ? $link : null, 'icon' => $icon !== '' ? $icon : null, 'description' => $desc !== '' ? $desc : null,
                'parent_id' => $parent, 'is_visible' => !empty($in['is_visible']) ? 1 : 0, 'new_tab' => !empty($in['new_tab']) ? 1 : 0];
        if ($existing === null) {
            $row['sort_order'] = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM menu_items WHERE menu_id = ?', [$m['id']]);
            $id = $this->db->insert('menu_items', $row + ['menu_id' => $m['id']]);
            AuditLogger::log('content.menu_item_added', 'menu', (int) $m['id'], null, $row + ['item' => $id], $actorId);
        } else {
            $id = (int) $existing['id'];
            $sets = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($row)));
            $this->db->exec("UPDATE menu_items SET $sets WHERE id = ?", [...array_values($row), $id]);
            AuditLogger::log('content.menu_item_updated', 'menu', (int) $m['id'], array_intersect_key($existing, $row), $row + ['item' => $id], $actorId);
        }
        self::flushCache();
        return $id;
    }

    public function deleteMenuItem(string $menuSlug, int $itemId, int $actorId): void
    {
        $m = $this->menuBySlug($menuSlug);
        $i = $this->item((int) $m['id'], $itemId);
        $this->db->exec('DELETE FROM menu_items WHERE id = ?', [$itemId]);   // drop-down items go with it (ON DELETE CASCADE)
        AuditLogger::log('content.menu_item_deleted', 'menu', (int) $m['id'], ['item' => $itemId, 'label' => $i['label'], 'link' => $i['link']], null, $actorId);
        self::flushCache();
    }

    /** Moves an item one place up or down among its siblings. */
    public function moveMenuItem(string $menuSlug, int $itemId, string $direction, int $actorId): void
    {
        $m = $this->menuBySlug($menuSlug);
        $i = $this->item((int) $m['id'], $itemId);
        $siblings = $this->db->all('SELECT id, sort_order FROM menu_items WHERE menu_id = ? AND parent_id <=> ? ORDER BY sort_order, id', [$m['id'], $i['parent_id']]);
        $this->swap('menu_items', $siblings, $itemId, $direction);
        AuditLogger::log('content.menu_item_moved', 'menu', (int) $m['id'], null, ['item' => $itemId, 'direction' => $direction], $actorId);
        self::flushCache();
    }

    /** @return array<string,mixed> */
    private function item(int $menuId, int $itemId): array
    {
        return $this->db->one('SELECT * FROM menu_items WHERE id = ? AND menu_id = ?', [$itemId, $menuId]) ?? throw HttpException::notFound();
    }

    /** Renumbers a list after moving one row, so the order is always 10, 20, 30… @param list<array<string,mixed>> $rows */
    private function swap(string $table, array $rows, int $id, string $direction): void
    {
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $pos = array_search($id, $ids, true);
        $to = $direction === 'up' ? $pos - 1 : $pos + 1;
        if ($pos === false || $to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
        $this->db->transaction(function () use ($table, $ids) {
            foreach ($ids as $n => $rid) {
                $this->db->exec("UPDATE `$table` SET sort_order = ? WHERE id = ?", [($n + 1) * 10, $rid]);
            }
        });
    }

    // ================================================================== homepage sections

    /** @return list<array<string,mixed>> the sections visitors see now, in order */
    public function liveSections(): array
    {
        try {
            return $this->db->all(
                'SELECT s.*, c.name AS category_name, c.slug AS category_slug FROM home_sections s LEFT JOIN categories c ON c.id = s.category_id AND c.is_active = 1
                  WHERE s.is_visible = 1 AND (s.starts_at IS NULL OR s.starts_at <= NOW()) AND (s.ends_at IS NULL OR s.ends_at > NOW()) ORDER BY s.sort_order, s.id'
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function sections(): array
    {
        return $this->db->all('SELECT s.*, c.name AS category_name FROM home_sections s LEFT JOIN categories c ON c.id = s.category_id ORDER BY s.sort_order, s.id');
    }

    /** @return array<string,mixed> */
    public function section(int $id): array
    {
        return $this->db->one('SELECT * FROM home_sections WHERE id = ?', [$id]) ?? throw HttpException::notFound();
    }

    /** Creates ($id null, type from input) or updates a section. @param array<string,mixed> $in */
    public function saveSection(?int $id, array $in, int $actorId): int
    {
        $existing = $id !== null ? $this->section($id) : null;
        $type = $existing ? (string) $existing['type'] : (string) ($in['type'] ?? '');
        $errors = [];
        if (!isset(self::SECTION_TYPES[$type])) {
            throw new ValidationException(['type' => 'Choose what the section shows.']);
        }
        if (!$existing && !in_array($type, self::REPEATABLE_SECTIONS, true) && $this->db->value('SELECT 1 FROM home_sections WHERE type = ?', [$type])) {
            $errors['type'] = 'The homepage already has this section; edit or show it instead.';
        }
        $title = trim((string) ($in['title'] ?? ''));
        $subtitle = trim((string) ($in['subtitle'] ?? ''));
        $body = str_replace("\r\n", "\n", trim((string) ($in['body'] ?? '')));
        $limit = trim((string) ($in['item_limit'] ?? ''));
        $category = (string) ($in['category_id'] ?? '') !== '' ? (int) $in['category_id'] : null;
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = 'Enter a title (up to 120 characters).';
        }
        if (mb_strlen($subtitle) > 160) {
            $errors['subtitle'] = 'Keep it to 160 characters.';
        }
        if ($limit !== '' && (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 48)) {
            $errors['item_limit'] = 'Use a number from 1 to 48.';
        }
        if ($type === 'category_row' && ($category === null || !$this->db->value('SELECT 1 FROM categories WHERE id = ?', [$category]))) {
            $errors['category_id'] = 'Choose a category.';
        }
        if ($type === 'text' && ($body === '' || mb_strlen($body) > 5000)) {
            $errors['body'] = 'Write the text (up to 5,000 characters).';
        }
        $startsAt = self::dateTime($in['starts_at'] ?? '', 'starts_at', $errors);
        $endsAt = self::dateTime($in['ends_at'] ?? '', 'ends_at', $errors);
        if ($startsAt && $endsAt && $endsAt <= $startsAt) {
            $errors['ends_at'] = 'The end must be after the start.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $row = ['title' => $title, 'subtitle' => $subtitle !== '' ? $subtitle : null, 'body' => $type === 'text' ? $body : null,
                'category_id' => $type === 'category_row' ? $category : null, 'item_limit' => $limit !== '' ? (int) $limit : null,
                'is_visible' => !empty($in['is_visible']) ? 1 : 0, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'updated_by' => $actorId];
        if ($existing === null) {
            $row += ['type' => $type, 'sort_order' => (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM home_sections')];
            $id = $this->db->insert('home_sections', $row);
            AuditLogger::log('content.home_section_added', 'home_section', $id, null, array_diff_key($row, ['body' => 1]), $actorId);
            return $id;
        }
        $sets = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($row)));
        $this->db->exec("UPDATE home_sections SET $sets WHERE id = ?", [...array_values($row), $id]);
        AuditLogger::log('content.home_section_updated', 'home_section', (int) $id, array_intersect_key($existing, array_diff_key($row, ['body' => 1])),
            array_diff_key($row, ['body' => 1]), $actorId);
        return (int) $id;
    }

    public function moveSection(int $id, string $direction, int $actorId): void
    {
        $this->section($id);
        $this->swap('home_sections', $this->db->all('SELECT id, sort_order FROM home_sections ORDER BY sort_order, id'), $id, $direction);
        AuditLogger::log('content.home_section_moved', 'home_section', $id, null, ['direction' => $direction], $actorId);
    }

    public function deleteSection(int $id, int $actorId): void
    {
        $s = $this->section($id);
        if (!in_array($s['type'], self::REPEATABLE_SECTIONS, true)) {
            throw ValidationException::withMessage('Built-in sections cannot be deleted; hide them instead.');
        }
        $this->db->exec('DELETE FROM home_sections WHERE id = ?', [$id]);
        AuditLogger::log('content.home_section_deleted', 'home_section', $id, ['type' => $s['type'], 'title' => $s['title']], null, $actorId);
    }
}
