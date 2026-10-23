<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\HttpException;
use Waimart\Core\ValidationException;
use Waimart\Services\SiteContentService;

/** Ecommerce Stage 3: pages (validation, scheduling, revisions), menus and homepage sections. */
final class SiteContentServiceTest extends DbTestCase
{
    private SiteContentService $svc;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SiteContentService($this->db);
        $this->staff = $this->db->insert('users', ['email' => 'editor@content.test', 'password_hash' => 'x', 'first_name' => 'Ed', 'last_name' => 'Itor', 'is_staff' => 1]);
    }

    private function refused(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail("refused: $field");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors, json_encode($e->errors));
        }
    }

    private function page(array $over = []): array
    {
        return $over + ['title' => 'Shipping policy', 'slug' => 'shipping-policy', 'body' => "## Shipping\nWe ship daily.", 'status' => 'published', 'sort_order' => '100', 'show_in_nav' => '1'];
    }

    public function testShippedContentIsComplete(): void
    {
        foreach (SiteContentService::SYSTEM_PAGES as $slug) {
            $this->assertNotNull($this->svc->livePage($slug), "built-in page $slug is live");
        }
        $this->assertCount(8, $this->svc->navPages(), 'Help Center menu: all built-in pages except Pickup stations');
        $this->assertSame(['Help Center', 'How to pay', 'Delivery & pickup', 'Returns & refunds', 'Contact us'], array_column($this->svc->menu('help_menu'), 'label'));
        $this->assertSame('About Ecommerce', $this->svc->menuTitle('footer_col_3'), '{store} filled in');
        $this->assertSame(['hero', 'quick_categories', 'flash_sale', 'recommended', 'top_deals', 'shop_by_category', 'featured', 'category_rails', 'values'], array_column($this->svc->liveSections(), 'type'));
    }

    public function testPageValidationAndAddresses(): void
    {
        $this->refused(fn () => $this->svc->savePage(null, $this->page(['slug' => 'Bad Slug!']), $this->staff), 'slug');
        $this->refused(fn () => $this->svc->savePage(null, $this->page(['slug' => 'terms']), $this->staff), 'slug');          // a built-in address
        $this->refused(fn () => $this->svc->savePage(null, $this->page(['title' => '']), $this->staff), 'title');
        $this->refused(fn () => $this->svc->savePage(null, $this->page(['body' => '   ']), $this->staff), 'body');
        $this->refused(fn () => $this->svc->savePage(null, $this->page(['status' => 'live']), $this->staff), 'status');
        $this->refused(fn () => $this->svc->savePage(null, $this->page(['publish_at' => '2026-10-20T10:00', 'unpublish_at' => '2026-10-20T09:00']), $this->staff), 'unpublish_at');
        $id = $this->svc->savePage(null, $this->page(), $this->staff);
        $this->refused(fn () => $this->svc->savePage(null, $this->page(), $this->staff), 'slug');                                // taken
        $this->assertSame('/pages/shipping-policy', SiteContentService::pageUrl('shipping-policy'));
        $this->assertSame('/terms', SiteContentService::pageUrl('terms'));

        // Built-in pages keep their address whatever is posted, and cannot be deleted; custom pages can.
        $terms = (int) $this->db->value("SELECT id FROM content_pages WHERE slug = 'terms'");
        $this->svc->savePage($terms, $this->page(['title' => 'Terms', 'slug' => 'hijacked']), $this->staff);
        $this->assertSame('terms', $this->db->value('SELECT slug FROM content_pages WHERE id = ?', [$terms]));
        $this->refused(fn () => $this->svc->deletePage($terms, $this->staff), '_form');
        $this->svc->deletePage($id, $this->staff);
        $this->assertNull($this->svc->livePage('shipping-policy'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'content.page_deleted'"));
    }

    public function testDraftsAndSchedulingDecideVisibility(): void
    {
        $id = $this->svc->savePage(null, $this->page(['status' => 'draft']), $this->staff);
        $this->assertNull($this->svc->livePage('shipping-policy'), 'draft');
        $this->svc->savePage($id, $this->page(['publish_at' => date('Y-m-d\TH:i', time() + 86400)]), $this->staff);
        $this->assertNull($this->svc->livePage('shipping-policy'), 'scheduled for tomorrow');
        $this->svc->savePage($id, $this->page(['publish_at' => date('Y-m-d\TH:i', time() - 3600)]), $this->staff);
        $this->assertNotNull($this->svc->livePage('shipping-policy'), 'started');
        $this->assertContains('shipping-policy', array_column($this->svc->navPages(), 'slug'));
        $this->svc->savePage($id, $this->page(['publish_at' => date('Y-m-d\TH:i', time() - 7200), 'unpublish_at' => date('Y-m-d\TH:i', time() - 60)]), $this->staff);
        $this->assertNull($this->svc->livePage('shipping-policy'), 'ended');
    }

    public function testEverySaveKeepsARevisionThatCanBeRestored(): void
    {
        $about = (int) $this->db->value("SELECT id FROM content_pages WHERE slug = 'about'");
        $original = (string) $this->db->value('SELECT body FROM content_pages WHERE id = ?', [$about]);
        $this->svc->savePage($about, ['title' => 'About {store}', 'body' => 'New text v1', 'status' => 'published', 'sort_order' => '70', 'show_in_nav' => '1'], $this->staff);
        $this->svc->savePage($about, ['title' => 'About {store}', 'body' => 'New text v2', 'status' => 'published', 'sort_order' => '70', 'show_in_nav' => '1'], $this->staff);
        $revs = $this->svc->revisions($about);
        $this->assertCount(2, $revs);
        $oldest = (int) end($revs)['id'];
        $this->svc->restoreRevision($about, $oldest, $this->staff);
        $this->assertSame($original, $this->db->value('SELECT body FROM content_pages WHERE id = ?', [$about]), 'the shipped text is back');
        $this->assertCount(3, $this->svc->revisions($about), 'and v2 was kept, so the restore can be undone');
        $this->assertSame('New text v2', $this->db->value('SELECT body FROM content_page_revisions WHERE page_id = ? ORDER BY id DESC LIMIT 1', [$about]));

        $other = (int) $this->db->value("SELECT id FROM content_pages WHERE slug = 'terms'");
        $this->expectException(HttpException::class);
        $this->svc->restoreRevision($other, $oldest, $this->staff);   // a revision of another page
    }

    public function testRevisionsAreCapped(): void
    {
        $id = $this->svc->savePage(null, $this->page(), $this->staff);
        for ($i = 0; $i < 33; $i++) {
            $this->svc->savePage($id, $this->page(['body' => "version $i"]), $this->staff);
        }
        $this->assertCount(30, $this->svc->revisions($id));
    }

    public function testMenuItems(): void
    {
        $this->refused(fn () => $this->svc->saveMenuItem('footer_col_1', null, ['label' => 'Bad', 'link' => 'javascript:alert(1)', 'is_visible' => '1'], $this->staff), 'link');
        $this->refused(fn () => $this->svc->saveMenuItem('footer_col_1', null, ['label' => 'No link', 'link' => '', 'is_visible' => '1'], $this->staff), 'link');   // footer links need one
        $this->refused(fn () => $this->svc->saveMenuItem('footer_highlights', null, ['label' => 'x', 'icon' => 'bi bi-x"><script>', 'is_visible' => '1'], $this->staff), 'icon');
        $this->refused(fn () => $this->svc->saveMenuItem('footer_col_1', null, ['label' => '', 'link' => '/x', 'is_visible' => '1'], $this->staff), 'label');

        $id = $this->svc->saveMenuItem('footer_col_1', null, ['label' => 'Shipping on {store}', 'link' => '/pages/shipping-policy', 'is_visible' => '1'], $this->staff);
        $items = $this->svc->menu('footer_col_1');
        $this->assertSame('Shipping on Ecommerce', end($items)['label'], 'added at the end, {store} filled in');
        $this->svc->moveMenuItem('footer_col_1', $id, 'up', $this->staff);
        $this->assertSame('Shipping on Ecommerce', $this->svc->menu('footer_col_1')[3]['label'], 'moved up one place');
        $this->svc->saveMenuItem('footer_col_1', $id, ['label' => 'Shipping', 'link' => '/pages/shipping-policy'], $this->staff);   // is_visible unticked
        $this->assertNotContains('Shipping', array_column($this->svc->menu('footer_col_1'), 'label'), 'hidden items are not shown');

        // Drop-downs: one level, only in menus that show them.
        $help = (int) $this->db->value("SELECT i.id FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE m.slug = 'help_menu' ORDER BY i.sort_order LIMIT 1");
        $child = $this->svc->saveMenuItem('help_menu', null, ['label' => 'Size guide', 'link' => '/pages/size-guide', 'parent_id' => (string) $help, 'is_visible' => '1'], $this->staff);
        $this->assertSame(['Size guide'], array_column($this->svc->menu('help_menu')[0]['children'], 'label'));
        $this->refused(fn () => $this->svc->saveMenuItem('help_menu', null, ['label' => 'Deeper', 'link' => '/x', 'parent_id' => (string) $child, 'is_visible' => '1'], $this->staff), 'parent_id');
        $this->refused(fn () => $this->svc->saveMenuItem('footer_col_2', null, ['label' => 'Nested', 'link' => '/x', 'parent_id' => (string) $help, 'is_visible' => '1'], $this->staff), 'parent_id');
        $this->svc->deleteMenuItem('help_menu', $help, $this->staff);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM menu_items WHERE id = ?', [$child]), 'its drop-down items go with it');

        // Another menu's item cannot be edited through this one.
        $this->expectException(HttpException::class);
        $this->svc->saveMenuItem('footer_col_2', $id, ['label' => 'x', 'link' => '/x'], $this->staff);
    }

    public function testMobileBarShowsAtMostFiveItems(): void
    {
        $this->svc->saveMenuItem('mobile_nav', null, ['label' => 'Deals', 'link' => '/flash-sales', 'icon' => 'lightning', 'is_visible' => '1'], $this->staff);
        $this->assertCount(5, $this->svc->menu('mobile_nav'));
    }

    public function testHomepageSections(): void
    {
        $this->refused(fn () => $this->svc->saveSection(null, ['type' => 'top_deals', 'title' => 'Again', 'is_visible' => '1'], $this->staff), 'type');      // exists once
        $this->refused(fn () => $this->svc->saveSection(null, ['type' => 'category_row', 'title' => 'Phones', 'is_visible' => '1'], $this->staff), 'category_id');
        $this->refused(fn () => $this->svc->saveSection(null, ['type' => 'text', 'title' => 'Hi', 'body' => '', 'is_visible' => '1'], $this->staff), 'body');
        $this->refused(fn () => $this->svc->saveSection(null, ['type' => 'nonsense', 'title' => 'x'], $this->staff), 'type');
        $cat = $this->db->insert('categories', ['name' => 'Phones', 'slug' => 'phones-x']);
        $row = $this->svc->saveSection(null, ['type' => 'category_row', 'title' => 'Phones for you', 'category_id' => (string) $cat, 'item_limit' => '8', 'is_visible' => '1'], $this->staff);
        $text = $this->svc->saveSection(null, ['type' => 'text', 'title' => 'Welcome', 'body' => 'Hello **world**', 'is_visible' => '1',
            'starts_at' => date('Y-m-d\TH:i', time() + 3600)], $this->staff);
        $types = array_column($this->svc->liveSections(), 'type');
        $this->assertSame('category_row', end($types));
        $this->assertNotContains('text', $types, 'scheduled for later');

        $this->svc->moveSection($row, 'up', $this->staff);
        $live = array_column($this->svc->liveSections(), 'type');
        $this->assertSame(['category_row', 'values'], array_slice($live, -2), 'moved above the values strip');
        $deals = (int) $this->db->value("SELECT id FROM home_sections WHERE type = 'top_deals'");
        $this->svc->saveSection($deals, ['title' => 'Top Deals', 'is_visible' => ''], $this->staff);
        $this->assertNotContains('top_deals', array_column($this->svc->liveSections(), 'type'), 'hidden');
        $this->refused(fn () => $this->svc->deleteSection($deals, $this->staff), '_form');
        $this->svc->deleteSection($text, $this->staff);
        $this->assertSame(5, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'content.home_section_%'"), 'added ×2, moved, updated, deleted');
    }
}
