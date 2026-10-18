<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Services\Brand;

/** Colour maths behind the settings-driven palette (WCAG 2.x contrast). */
final class BrandTest extends TestCase
{
    public function testHexNormalisation(): void
    {
        $this->assertSame('#005B3D', Brand::hex('005b3d'));
        $this->assertSame('#FF6A00', Brand::hex(' #ff6a00 '));
        $this->assertSame('#AABBCC', Brand::hex('#abc'));
        foreach (['', 'red', '#12345', '#1234567', 'url(x)', '#GGGGGG', '#0b5a3a;}body{'] as $bad) {
            $this->assertNull(Brand::hex($bad), "rejects '$bad'");
        }
    }

    public function testContrastMatchesKnownValues(): void
    {
        $this->assertEqualsWithDelta(21.0, Brand::contrast('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(1.0, Brand::contrast('#777777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(8.19, Brand::contrast('#005B3D', '#FFFFFF'), 0.01, 'Basemall green with white text');
        $this->assertEqualsWithDelta(2.87, Brand::contrast('#FF6A00', '#FFFFFF'), 0.01, 'logo orange is too light for white text');
    }

    public function testButtonColourIsAlwaysReadable(): void
    {
        foreach (['#FF6A00', '#FFD200', '#FFFFFF', '#00FF00', '#C85200', '#005B3D'] as $c) {
            $b = Brand::readableOnWhiteText($c);
            $this->assertGreaterThanOrEqual(4.5, Brand::contrast($b, '#FFFFFF'), "$c → $b");
        }
        $this->assertSame('#005B3D', Brand::readableOnWhiteText('#005B3D'), 'an already-readable colour is unchanged');
    }

    public function testShadesAndMixes(): void
    {
        $this->assertSame('#FFFFFF', Brand::mix('#005B3D', '#FFFFFF', 1.0));
        $this->assertSame('#005B3D', Brand::mix('#005B3D', '#FFFFFF', 0.0));
        $this->assertSame('#000000', Brand::shade('#FF6A00', -1.0));
        $this->assertLessThan(Brand::contrast('#FF6A00', '#000000'), Brand::contrast(Brand::shade('#FF6A00', -0.2), '#000000'), 'darker = closer to black');
    }
}
