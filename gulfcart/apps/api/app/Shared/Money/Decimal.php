<?php

namespace App\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic on numeric strings (no floats, no ext-bcmath).
 * Results are rounded half-up to the requested scale.
 */
final class Decimal
{
    public static function add(string $a, string $b, int $scale = 4): string
    {
        return (string) BigDecimal::of($a)->plus($b)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function sub(string $a, string $b, int $scale = 4): string
    {
        return (string) BigDecimal::of($a)->minus($b)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function mul(string $a, string $b, int $scale = 4): string
    {
        return (string) BigDecimal::of($a)->multipliedBy($b)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function div(string $a, string $b, int $scale = 4): string
    {
        return (string) BigDecimal::of($a)->dividedBy($b, $scale, RoundingMode::HalfUp);
    }

    public static function round(string $a, int $scale = 2, RoundingMode $mode = RoundingMode::HalfUp): string
    {
        return (string) BigDecimal::of($a)->toScale($scale, $mode);
    }

    public static function ceil(string $a, int $scale = 0): string
    {
        return (string) BigDecimal::of($a)->toScale($scale, RoundingMode::Ceiling);
    }

    public static function cmp(string $a, string $b): int
    {
        return BigDecimal::of($a)->compareTo($b);
    }

    public static function isZero(string $a): bool
    {
        return BigDecimal::of($a)->isZero();
    }

    /** @param list<string> $values */
    public static function sum(array $values, int $scale = 4): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus($v);
        }

        return (string) $total->toScale($scale, RoundingMode::HalfUp);
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }
}
