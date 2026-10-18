<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Core\Money;

final class MoneyTest extends TestCase
{
    public function testAdditionIsExactWhereFloatsFail(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('3000.03', Money::add('1000.01', '1000.01', '1000.01'));
    }

    public function testMultiplyAndSubtract(): void
    {
        $this->assertSame('3599.97', Money::mul('1199.99', 3));
        $this->assertSame('-50.00', Money::sub('100', '150'));
    }

    public function testPercentRoundsHalfUp(): void
    {
        $this->assertSame('0.01', Money::percent('0.10', '5'));      // 0.005 -> 0.01
        $this->assertSame('150.00', Money::percent('1000', '15'));
        $this->assertSame('333.33', Money::percent('1000', '33.333'));
    }

    public function testRounding(): void
    {
        $this->assertSame('1.01', Money::normalize('1.005'));
        $this->assertSame('1.00', Money::normalize('1.004'));
        $this->assertSame('-1.01', Money::normalize('-1.005'));
        $this->assertSame('0.00', Money::normalize('-0.001'));
    }

    public function testComparisons(): void
    {
        $this->assertSame(1, Money::cmp('10.01', '10'));
        $this->assertSame('5.00', Money::min('5', '7'));
        $this->assertSame('7.00', Money::max('5', '7'));
        $this->assertTrue(Money::isZero('0.00'));
    }

    public function testFormat(): void
    {
        $this->assertSame('KSh 1,234,567', Money::format('1234567'));
        $this->assertSame('KSh 1,234.50', Money::format('1234.5'));
        $this->assertSame('KSh 0', Money::format('0'));
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::normalize('12abc');
    }
}
