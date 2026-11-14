<?php

namespace Tests\Unit;

use App\Modules\Pricing\Domain\PricingEngine;
use App\Modules\Pricing\Domain\PricingException;
use App\Modules\Pricing\Domain\PricingInput;
use App\Modules\Pricing\Domain\ResolvedRule;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    private function input(array $o = []): PricingInput
    {
        return new PricingInput(
            unitCost: $o['unitCost'] ?? '24',
            shippingCost: $o['shippingCost'] ?? '12',
            costCurrency: 'USD',
            saleCurrency: 'AED',
            fxRate: $o['fx'] ?? '3.6725',
            fxBufferPercent: $o['buffer'] ?? '0',
            importVatPercent: $o['importVat'] ?? '0',
            operationalAllocation: $o['allocation'] ?? '14.69', // 4 USD marketing allocation in AED
            vatPercent: $o['vat'] ?? '5',
            feePercent: $o['fee'] ?? '2.9',
            feeFixed: $o['feeFixed'] ?? '0',
            rule: $o['rule'] ?? new ResolvedRule('margin_percent', '20', null, null),
            rounding: $o['rounding'] ?? 'ceil_whole',
        );
    }

    public function test_worked_example_from_the_pricing_spec(): void
    {
        $r = (new PricingEngine)->price($this->input());

        $this->assertSame('146.9000', $r->variableCost);
        $this->assertSame('201.00', $r->grossPrice);
        $this->assertSame('191.43', $r->netPrice);
        $this->assertSame('9.57', $r->vatAmount);
        $this->assertSame('5.83', $r->paymentFee);
        $this->assertSame('38.70', $r->contribution);
        $this->assertSame('20.22', $r->marginPercent);
    }

    public function test_simple_margin_without_fees_or_vat(): void
    {
        $r = (new PricingEngine)->price($this->input(['unitCost' => '35', 'shippingCost' => '0', 'fx' => '1', 'allocation' => '0', 'vat' => '0', 'fee' => '0', 'rounding' => 'ceil_cent']));

        $this->assertSame('43.75', $r->grossPrice);
        $this->assertSame('20.00', $r->marginPercent);
    }

    public function test_saudi_vat_produces_a_different_price(): void
    {
        $r = (new PricingEngine)->price($this->input(['fx' => '3.75', 'allocation' => '15', 'vat' => '15']));

        $this->assertStringEndsWith('.00', $r->grossPrice);
        $this->assertSame(
            number_format((float) $r->grossPrice - (float) $r->netPrice, 2, '.', ''),
            $r->vatAmount,
        );
        $this->assertGreaterThanOrEqual(20.0, (float) $r->marginPercent);
    }

    public function test_minimum_margin_raises_a_low_markup(): void
    {
        $r = (new PricingEngine)->price($this->input(['rule' => new ResolvedRule('markup_percent', '5', '15', null)]));

        $this->assertGreaterThanOrEqual(15.0, (float) $r->marginPercent);
    }

    public function test_fixed_markup(): void
    {
        $r = (new PricingEngine)->price($this->input(['vat' => '0', 'fee' => '0', 'rule' => new ResolvedRule('fixed_markup', '50', null, null), 'rounding' => 'ceil_cent']));

        $this->assertSame('196.90', $r->grossPrice);
    }

    public function test_rounding_modes_only_round_up(): void
    {
        $e = new PricingEngine;
        $this->assertSame('209.00', $e->round('200.44', 'ceil_x9'));
        $this->assertSame('199.00', $e->round('195.00', 'ceil_x9'));
        $this->assertSame('209.00', $e->round('199.01', 'ceil_x9'));
        $this->assertSame('200.45', $e->round('200.41', 'ceil_0_05'));
        $this->assertSame('201.00', $e->round('200.01', 'ceil_whole'));
        $this->assertSame('200.00', $e->round('200.00', 'ceil_whole'));
    }

    public function test_fx_buffer_and_non_recoverable_import_vat_increase_cost(): void
    {
        $base = (new PricingEngine)->price($this->input());
        $buffered = (new PricingEngine)->price($this->input(['buffer' => '1.5', 'importVat' => '5']));

        $this->assertGreaterThan((float) $base->variableCost, (float) $buffered->variableCost);
        $this->assertGreaterThan((float) $base->grossPrice, (float) $buffered->grossPrice);
    }

    public function test_impossible_margin_is_rejected(): void
    {
        $this->expectException(PricingException::class);
        (new PricingEngine)->price($this->input(['rule' => new ResolvedRule('margin_percent', '98', null, null)]));
    }

    public function test_max_selling_price_is_enforced(): void
    {
        try {
            (new PricingEngine)->price($this->input(['rule' => new ResolvedRule('margin_percent', '20', null, '150')]));
            $this->fail('Expected PricingException');
        } catch (PricingException $e) {
            $this->assertSame('ABOVE_MAX_PRICE', $e->reason);
        }
    }
}
