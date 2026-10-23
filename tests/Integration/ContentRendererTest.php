<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Services\ContentRenderer;

/** The safe content format: formatting, live values and blocks, and that nothing typed can become markup. */
final class ContentRendererTest extends DbTestCase
{
    /** @var array<string,string> */
    private array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $k => $v) {
            Settings::set($k, (string) $v);
        }
        parent::tearDown();
    }

    private function r(string $src): string
    {
        return (new ContentRenderer())->render($src);
    }

    public function testFormatting(): void
    {
        $html = $this->r("Intro with **bold**, *italic* and a [link](/contact).\nSame paragraph.\n\n## Heading\n### Smaller\n- one\n- two\n\n1. first\n2. second\n\n> Note line one\n> line two");
        $this->assertStringContainsString('<p>Intro with <strong>bold</strong>, <em>italic</em> and a <a href="/contact">link</a>. Same paragraph.</p>', str_replace(url('/contact'), '/contact', $html));
        $this->assertStringContainsString('<h2>Heading</h2>', $html);
        $this->assertStringContainsString('<h3>Smaller</h3>', $html);
        $this->assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
        $this->assertStringContainsString('<div class="wm-callout my-3">Note line one<br>line two</div>', $html);
        $ext = $this->r('[Facebook](https://facebook.com/ecommerce) [mail](mailto:care@example.test) [call](tel:+254700123456)');
        $this->assertStringContainsString('href="https://facebook.com/ecommerce" rel="noopener" target="_blank"', $ext);
        $this->assertStringContainsString('href="mailto:care@example.test"', $ext);
        $this->assertStringContainsString('href="tel:+254700123456"', $ext);
    }

    public function testNothingTypedBecomesMarkup(): void
    {
        $attacks = [
            '<script>alert(1)</script>' => '&lt;script&gt;',
            '<img src=x onerror=alert(1)>' => '&lt;img',
            '[click](javascript:alert(1))' => 'click',
            '[click](//evil.example/x)' => 'click',
            '[click](data:text/html;base64,PHNjcmlwdD4=)' => 'click',
            '[x"onmouseover="alert(1)](/ok)' => 'x&quot;onmouseover=&quot;alert(1)',
            '## <b onclick=x>hi</b>' => '&lt;b onclick=x&gt;',
            '?? <svg onload=alert(1)>' => '&lt;svg onload=alert(1)&gt;',
            '**<iframe src=//evil>**' => '&lt;iframe',
        ];
        foreach ($attacks as $src => $expect) {
            $html = $this->r($src);
            $this->assertStringContainsString($expect, $html, $src);
            // No live tag, no event-handler attribute, no dangerous link: escaped text (&lt;…) is fine.
            $this->assertDoesNotMatchRegularExpression('/<(script|img|iframe|svg)\b|<b\s|\son\w+="|href="(javascript|data|\/\/)/i', $html, $src);
        }
        $this->assertStringNotContainsString('href', $this->r('[click](javascript:alert(1))'), 'an unsafe link keeps only its text');
    }

    public function testFaqAccordion(): void
    {
        $html = $this->r("Intro\n\n?? First question\nAnswer one.\n\nMore of answer one.\n\n?? Second?\n- a list in the answer\n\n## After");
        $this->assertSame(2, substr_count($html, 'class="accordion-item"'));
        $this->assertStringContainsString('aria-expanded="true" aria-controls="faq1-0">First question</button>', $html);
        $this->assertStringContainsString('<div class="accordion-body"><p>Answer one.</p><p>More of answer one.</p></div>', $html);
        $this->assertStringContainsString('<div class="accordion-body"><ul><li>a list in the answer</li></ul></div>', $html);
        $this->assertMatchesRegularExpression('#</div></div></div></div>\s*<h2>After</h2>#', $html, 'the heading ends the FAQ');
    }

    public function testLiveValuesAndBlocks(): void
    {
        Settings::set('return_window_days', '14');
        Settings::set('store_phone', '0700 123 456');
        Settings::set('mpesa_till_number', '5123456');
        Settings::set('mpesa_paybill_number', '');
        Settings::set('mpesa_phone_number', '');
        Settings::flush();
        $this->assertStringContainsString('Return within 14 days at Ecommerce {unknown}', $this->r('Return within {return_days} days at {store} {unknown}'));
        $this->assertSame('About Ecommerce', ContentRenderer::plain('About {store}'));

        $contact = $this->r("[[contact_details]]");
        $this->assertStringContainsString('0700 123 456', $contact);
        $pay = $this->r('[[payment_instructions]]');
        $this->assertStringContainsString('5123456', $pay);
        $this->assertStringContainsString('Buy Goods (Till)', $pay);
        $this->assertStringNotContainsString('Paybill</h2>', $pay, 'only methods that are switched on');
        $this->assertStringContainsString('Cash on delivery', $pay);

        $zone = $this->db->insert('pickup_points', ['code' => 'NBO1', 'name' => 'Moi Avenue Station', 'county_id' => 47, 'town' => 'CBD', 'address' => 'Moi Ave 12', 'opening_hours' => '8-6']);
        $this->assertStringContainsString('Moi Avenue Station', $this->r('[[pickup_stations]]'));
        $this->assertSame('', $this->r('[[no_such_block]]'), 'unknown blocks render nothing');
        $this->assertGreaterThan(0, $zone);
    }
}
