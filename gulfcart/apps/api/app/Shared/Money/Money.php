<?php

namespace App\Shared\Money;

use Brick\Math\RoundingMode;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;

/**
 * Immutable money value. Amounts are decimal strings; never floats.
 * Scale defaults to 2 (customer amounts); use scale 4 for supplier costs.
 */
final class Money
{
    private function __construct(private readonly BrickMoney $money) {}

    public static function of(string|int $amount, string $currency, int $scale = 2): self
    {
        if (is_string($amount) && preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        return new self(BrickMoney::of($amount, strtoupper($currency), new CustomContext($scale), RoundingMode::HalfUp));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->money->plus($other->money, RoundingMode::HalfUp));
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->money->minus($other->money, RoundingMode::HalfUp));
    }

    public function multipliedBy(string|int $factor, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        return new self($this->money->multipliedBy($factor, $rounding));
    }

    public function amount(): string
    {
        return (string) $this->money->getAmount();
    }

    public function currency(): string
    {
        return $this->money->getCurrency()->getCurrencyCode();
    }

    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->currency() === $other->currency() && $this->money->isEqualTo($other->money);
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->money->isGreaterThan($other->money);
    }

    /** @return array{amount: string, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount(), 'currency' => $this->currency()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency() !== $other->currency()) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency()} vs {$other->currency()}.");
        }
    }
}
