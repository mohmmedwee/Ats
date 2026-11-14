<?php

namespace Tests\Unit;

use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_decimal_arithmetic_has_no_float_error(): void
    {
        $sum = Money::of('0.10', 'AED')->plus(Money::of('0.20', 'AED'));

        $this->assertSame('0.30', $sum->amount());
        $this->assertTrue($sum->equals(Money::of('0.3', 'aed')));
    }

    public function test_multiplication_rounds_half_up_to_the_scale(): void
    {
        $this->assertSame('200.43', Money::of('190.89', 'AED')->multipliedBy('1.05')->amount());
    }

    public function test_cost_scale_keeps_four_decimals(): void
    {
        $this->assertSame('88.1400', Money::of('24', 'USD', 4)->multipliedBy('3.6725')->amount());
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.00', 'AED')->plus(Money::of('1.00', 'SAR'));
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1e3', 'AED');
    }

    public function test_to_array_uses_string_amounts(): void
    {
        $this->assertSame(['amount' => '89.00', 'currency' => 'SAR'], Money::of('89', 'SAR')->toArray());
    }
}
