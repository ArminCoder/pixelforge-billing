<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_reads_decimal_strings_without_floats(): void
    {
        $this->assertSame(12_500_000_000, Money::fromDecimal('12.50'));
        $this->assertSame(5_000_000_000, Money::fromDecimal('5'));
        $this->assertSame(1, Money::fromDecimal('0.000000001'));
        $this->assertSame(373_875_000, Money::fromDecimal('0.373875000'));
    }

    public function test_it_refuses_values_it_cannot_hold_exactly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('0.0000000001'); // 10 decimals
    }

    public function test_it_writes_nine_decimals_like_pixelforge(): void
    {
        $this->assertSame('0.373875000', Money::toDecimal(373_875_000));
        $this->assertSame('5.000000000', Money::toDecimal(5_000_000_000));
        $this->assertSame('-0.000000001', Money::toDecimal(-1));
    }

    public function test_division_rounds_up_only_when_there_is_a_remainder(): void
    {
        $this->assertSame(5, Money::divideRoundingUp(10, 2));
        $this->assertSame(4, Money::divideRoundingUp(10, 3));
        $this->assertSame(0, Money::divideRoundingUp(0, 3));
    }
}
