<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

/** Ecommerce Stage 3 over HTTP: content admin permissions, and edits to pages, menus and the homepage going live. */
final class ContentHttpTest extends FeatureTestCase
{
    private function pageId(string $slug): int
    {
        return (int) $this->db->value('SELECT id FROM content_pages WHERE slug = ?', [$slug]);
    }

    public function testOnlyContentManagersReachTheContentAdmin(): void
    {
        $paths = ['/admin/content/pages', '/admin/content/pages/new', '/admin/content/pages/' . $this->pageId('about'), '/admin/content/menus',
                  '/admin/content/menus/footer_col_1', '/admin/content/homepage', '/admin/content/homepage/new'];
        foreach (['support', 'finance', 'fulfilment_staff'] as $role) {
            $this->post('/logout', [], '/');
            $this->loginStaff($role);
            foreach ($paths as $p) {
                $this->assertSame(403, $this->get($p)['status'], "$role GET $p");
            }
            $this->assertSame(403, $this->post('/admin/content/pages/' . $this->pageId('about'), ['title' => 'Hacked', 'body' => 'x', 'status' => 'published'], '/admin')['status']);
        }
        $this->assertStringNotContainsString('Hacked', (string) $this->db->value("SELECT title FROM content_pages WHERE slug = 'about'"));
        $this->post('/logout', [], '/');
        $this->loginStaff('manager');                 // holds content.manage
        foreach ($paths as $p) {
            $this->assertSame(200, $this->get($p)['status'], "manager GET $p");
        }
    }

    public function testEditingAPageAndDraftPreview(): void
    {
        $this->loginStaff('manager');
        $id = $this->pageId('returns-policy');
        $r = $this->post("/admin/content/pages/$id", ['title' => 'Returns & refunds', 'meta_description' => 'Returns at {store}',
            'body' => "Return within **{return_days} days**.\n\n?? Can I return shoes?\nYes, unworn.", 'status' => 'published', 'sort_order' => '50', 'show_in_nav' => '1'],
            "/admin/content/pages/$id");
        $this->assertSame(303, $r['status']);
        $page = $this->get('/returns-policy')['body'];
        $this->assertStringContainsString('Return within <strong>7 days</strong>.', $page);
        $this->assertStringContainsString('Can I return shoes?', $page);
        $this->assertStringContainsString('content="Returns at Ecommerce"', $page);

        // A new page as a draft: staff can preview, visitors get 404; once published it is public.
        $this->post('/admin/content/pages/new', ['title' => 'Size guide', 'slug' => 'size-guide', 'body' => 'Measure twice.', 'status' => 'draft', 'sort_order' => '120', 'show_in_nav' => '1'], '/admin/content/pages/new');
        $preview = $this->get('/pages/size-guide');
        $this->assertSame(200, $preview['status']);
        $this->assertStringContainsString('Preview: this page is not published', $preview['body']);
        $this->post('/logout', [], '/');
        $this->assertSame(404, $this->get('/pages/size-guide')['status']);
        $this->assertStringNotContainsString('Size guide', $this->get('/help')['body'], 'drafts are not in the Help menu');
        $this->db->exec("UPDATE content_pages SET status = 'published' WHERE slug = 'size-guide'");
        $this->assertStringContainsString('Measure twice.', $this->get('/pages/size-guide')['body']);
        $this->assertStringContainsString('href="/pages/size-guide"', $this->get('/help')['body']);
        $this->assertSame(301, $this->get('/pages/terms')['status'], 'built-in pages answer at their own address');
    }

    public function testMenusAndFooterFollowTheContentAdmin(): void
    {
        $this->loginStaff('manager');
        $this->post('/admin/content/menus/footer_col_2/title', ['menu_title' => 'Shop with us'], '/admin/content/menus/footer_col_2');
        $this->post('/admin/content/menus/footer_col_2/items', ['label' => 'Gift cards', 'link' => '/pages/gift-cards', 'is_visible' => '1'], '/admin/content/menus/footer_col_2');
        $this->post('/admin/content/menus/topbar_messages/items', ['label' => 'Same-day delivery in {store} City', 'icon' => 'lightning', 'is_visible' => '1'], '/admin/content/menus/topbar_messages');
        $bad = $this->post('/admin/content/menus/footer_col_2/items', ['label' => 'Evil', 'link' => 'javascript:alert(1)', 'is_visible' => '1'], '/admin/content/menus/footer_col_2');
        $this->assertStringContainsString('Use a page on this site', $this->follow($bad)['body']);
        $this->post('/admin/settings/general', ['footer_note' => 'All prices include VAT.', 'products_per_page' => '24'], '/admin/settings/general');   // manager lacks settings.manage

        $this->post('/logout', [], '/');
        $home = $this->get('/')['body'];
        $this->assertStringContainsString('<h3>Shop with us</h3>', $home);
        $this->assertStringContainsString('href="/pages/gift-cards">Gift cards</a>', $home);
        $this->assertStringContainsString('Same-day delivery in Ecommerce City', $home);
        $this->assertStringNotContainsString('Evil', $home);
        $this->assertStringNotContainsString('All prices include VAT.', $home, 'the footer note is a setting: managers cannot change it');
        $this->assertStringContainsString('Prices in Kenya Shillings (KSh), VAT inclusive where applicable.', $home);
        $this->assertSame(5, substr_count(explode('wm-bottom-nav', $home)[1] ?? '', '<a href'), 'phone bar from its menu');
    }

    public function testHomepageSectionsFollowTheContentAdmin(): void
    {
        $this->makeVariant(5);
        $this->loginStaff('manager');
        $values = (int) $this->db->value("SELECT id FROM home_sections WHERE type = 'values'");
        $this->post("/admin/content/homepage/$values", ['title' => 'Why shop with us'], "/admin/content/homepage/$values");        // hidden
        $this->post('/admin/content/homepage/new', ['type' => 'text', 'title' => 'Holiday hours', 'body' => 'Open **every day** until 24 December.', 'is_visible' => '1'], '/admin/content/homepage/new?type=text');
        $this->post('/logout', [], '/');
        $home = $this->get('/')['body'];
        $this->assertStringNotContainsString('class="wm-values"', $home);
        $this->assertStringContainsString('Holiday hours</h2>', $home);
        $this->assertStringContainsString('Open <strong>every day</strong> until 24 December.', $home);
    }
}
