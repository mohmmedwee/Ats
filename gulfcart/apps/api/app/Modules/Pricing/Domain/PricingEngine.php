<?php

namespace App\Modules\Pricing\Domain;

use App\Shared\Money\Decimal;

/**
 * Deterministic retail price calculation (docs/PRICING_ENGINE.md).
 *
 *   C   = (unit cost + supplier shipping) × fx × (1 + buffer) × (1 + non-recoverable import VAT) + allocation
 *   P   = (C + k) / (1 − m − f(1 + v))          for margin_percent rules
 *   G   = round_up(P × (1 + v))                 VAT-inclusive customer price
 * Rounding only goes up, and the achieved margin is re-checked after rounding.
 */
final class PricingEngine
{
    private const S = 8; // internal scale

    public function price(PricingInput $in): PricingResult
    {
        $v = Decimal::div($in->vatPercent, '100', self::S);
        $f = Decimal::div($in->feePercent, '100', self::S);
        $k = $in->feeFixed;
        $onePlusV = Decimal::add('1', $v, self::S);

        $fx = Decimal::mul($in->fxRate, Decimal::add('1', Decimal::div($in->fxBufferPercent, '100', self::S), self::S), self::S);
        $landed = Decimal::mul(Decimal::add($in->unitCost, $in->shippingCost, self::S), $fx, self::S);
        $landed = Decimal::mul($landed, Decimal::add('1', Decimal::div($in->importVatPercent, '100', self::S), self::S), self::S);
        $c = Decimal::add($landed, $in->operationalAllocation, self::S);
        $feeOnNet = Decimal::mul($f, $onePlusV, self::S); // fee rate expressed on net price

        $rule = $in->rule;
        $net = match ($rule->type) {
            'margin_percent' => $this->forMargin($c, $k, Decimal::div($rule->value, '100', self::S), $feeOnNet),
            'markup_percent' => Decimal::mul($c, Decimal::add('1', Decimal::div($rule->value, '100', self::S), self::S), self::S),
            'fixed_markup' => Decimal::add($c, $rule->value, self::S),
            default => throw new PricingException('INVALID_RULE', "Unknown rule type {$rule->type}."),
        };

        if ($rule->minMarginPercent !== null) {
            $floor = $this->forMargin($c, $k, Decimal::div($rule->minMarginPercent, '100', self::S), $feeOnNet);
            $net = Decimal::max($net, $floor);
        }

        $gross = $this->round(Decimal::mul($net, $onePlusV, self::S), $in->rounding);
        if ($rule->maxSellingPrice !== null && Decimal::cmp($gross, $rule->maxSellingPrice) > 0) {
            throw new PricingException('ABOVE_MAX_PRICE', "Price {$gross} exceeds the maximum {$rule->maxSellingPrice}.");
        }

        $netPrice = Decimal::round(Decimal::div($gross, $onePlusV, self::S), 2);
        $vat = Decimal::sub($gross, $netPrice, 2);
        $fee = Decimal::round(Decimal::add(Decimal::mul($f, $gross, self::S), $k, self::S), 2);
        $contribution = Decimal::sub(Decimal::sub($netPrice, $c, self::S), $fee, 2);
        $margin = Decimal::cmp($netPrice, '0') > 0 ? Decimal::round(Decimal::mul(Decimal::div($contribution, $netPrice, self::S), '100', self::S), 2) : '0.00';

        if ($rule->minMarginPercent !== null && Decimal::cmp($margin, Decimal::round($rule->minMarginPercent, 2)) < 0) {
            throw new PricingException('BELOW_MIN_MARGIN', "Margin {$margin}% is below the minimum {$rule->minMarginPercent}%.");
        }

        return new PricingResult($in->saleCurrency, Decimal::round($c, 4), $netPrice, $vat, $gross, $fee, $contribution, $margin, Decimal::round($fx, 8));
    }

    private function forMargin(string $c, string $k, string $m, string $feeOnNet): string
    {
        $denominator = Decimal::sub(Decimal::sub('1', $m, self::S), $feeOnNet, self::S);
        if (Decimal::cmp($denominator, '0') <= 0) {
            throw new PricingException('INVALID_RULE', 'Target margin plus payment fees must be below 100%.');
        }

        return Decimal::div(Decimal::add($c, $k, self::S), $denominator, self::S);
    }

    public function round(string $amount, string $mode): string
    {
        return match ($mode) {
            'ceil_whole' => Decimal::round(Decimal::ceil($amount, 0), 2),
            'ceil_0_05' => Decimal::round(Decimal::mul(Decimal::ceil(Decimal::mul($amount, '20', self::S), 0), '0.05', self::S), 2),
            'ceil_x9' => $this->ceilX9($amount),
            default => Decimal::round(Decimal::ceil($amount, 2), 2),
        };
    }

    /** Next price ending in 9 (e.g. 200.44 → 209.00, 195.00 → 199.00). */
    private function ceilX9(string $amount): string
    {
        $whole = (int) Decimal::ceil($amount, 0);
        $candidate = intdiv($whole, 10) * 10 + 9;

        return Decimal::round((string) ($candidate < $whole ? $candidate + 10 : $candidate), 2);
    }
}
