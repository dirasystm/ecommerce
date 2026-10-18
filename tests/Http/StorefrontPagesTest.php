<?php

declare(strict_types=1);

namespace Waimart\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Waimart\Controllers\PageController;

final class StorefrontPagesTest extends HttpTestCase
{
    public function testHomeRendersLayoutLandmarksAndAssets(): void
    {
        $r = $this->request('GET', '/');
        $this->assertSame(200, $r['status']);
        $b = $r['body'];
        $this->assertStringContainsString('<html lang="en-KE">', $b);
        $this->assertStringContainsString('class="skip-link" href="#main"', $b);
        $this->assertStringContainsString('<main id="main"', $b);
        $this->assertStringContainsString('role="search"', $b);
        $this->assertStringContainsString('vendor/bootstrap/bootstrap.min.css', $b);
        $this->assertStringContainsString('class="wm-footer"', $b);
        $this->assertStringNotContainsString('<script>', $b, 'no inline scripts (CSP)');
        $this->assertStringNotContainsString('cdn.', $b, 'all assets are served locally');
    }

    public function testEveryImageHasAltAttribute(): void
    {
        $b = $this->request('GET', '/')['body'];
        preg_match_all('/<img\b[^>]*>/i', $b, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $img) {
            $this->assertMatchesRegularExpression('/\salt="/', $img, "Missing alt: $img");
        }
    }

    public static function pages(): array
    {
        return array_map(fn ($s) => [$s], array_keys(PageController::PAGES));
    }

    #[DataProvider('pages')]
    public function testContentPagesLoad(string $slug): void
    {
        $r = $this->request('GET', '/' . $slug);
        $this->assertSame(200, $r['status'], "/$slug");
        $this->assertStringContainsString('aria-current="page"', $r['body']);
    }

    public function testCategoriesPageLoads(): void
    {
        $this->assertSame(200, $this->request('GET', '/categories')['status']);
    }

    public function testSearchQueryIsEscapedWhenReflected(): void
    {
        // The header reflects ?q= into the search box on every page.
        $r = $this->request('GET', '/help?q=' . rawurlencode('"><script>alert(1)</script>'));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $r['body']);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $r['body']);
    }

    public function testHowToPayNeverClaimsSubmissionEqualsPayment(): void
    {
        $b = $this->request('GET', '/how-to-pay')['body'];
        $this->assertStringContainsString('does not complete your payment on its own', $b);
        $this->assertStringContainsString('never</strong> ask for your M-Pesa PIN', $b);
    }

    public function testUploadsCannotExecuteScripts(): void
    {
        $r = $this->request('GET', '/uploads/.htaccess');
        $this->assertContains($r['status'], [403, 404]);
    }
}
