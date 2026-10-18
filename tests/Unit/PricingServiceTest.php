<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Services\PricingService;

final class PricingServiceTest extends TestCase
{
    public function testPlainPrice(): void
    {
        $r = PricingService::resolve('1000', null, null);
        $this->assertSame(['price' => '1000.00', 'was' => null, 'discount_percent' => 0, 'on_sale' => false], $r);
    }

    public function testCompareAtPriceGivesDiscount(): void
    {
        $r = PricingService::resolve('750', '1000', null);
        $this->assertSame('750.00', $r['price']);
        $this->assertSame('1000.00', $r['was']);
        $this->assertSame(25, $r['discount_percent']);
    }

    public function testFlashSaleUsesHigherReferencePrice(): void
    {
        $r = PricingService::resolve('15999', '18999', '14499');
        $this->assertSame('14499.00', $r['price']);
        $this->assertSame('18999.00', $r['was']);
        $this->assertSame(23, $r['discount_percent']);     // 23.68 floored — never overstated
        $this->assertTrue($r['on_sale']);
    }

    public function testFlashSaleWithoutCompareAtUsesBasePriceAsWas(): void
    {
        $r = PricingService::resolve('1000', null, '900');
        $this->assertSame('1000.00', $r['was']);
        $this->assertSame(10, $r['discount_percent']);
    }

    public function testSalePriceHigherThanBaseIsIgnored(): void
    {
        $r = PricingService::resolve('1000', null, '1200');
        $this->assertSame('1000.00', $r['price']);
        $this->assertFalse($r['on_sale']);
    }

    public function testCompareAtNotAboveCurrentPriceIsHidden(): void
    {
        $r = PricingService::resolve('1000', '1000', null);
        $this->assertNull($r['was']);
        $this->assertSame(0, $r['discount_percent']);
    }
}
