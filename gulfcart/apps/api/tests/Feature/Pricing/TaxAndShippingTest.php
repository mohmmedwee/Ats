<?php

namespace Tests\Feature\Pricing;

use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Tax\Contracts\TaxRules;
use App\Modules\Tax\Domain\DutyLine;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class TaxAndShippingTest extends TestCase
{
    use CatalogFixtures;

    public function test_vat_rates_are_per_country_and_category_overrides_apply(): void
    {
        $tax = app(TaxRules::class);
        $this->assertSame('5.0000', $tax->vatRate('AE'));
        $this->assertSame('15.0000', $tax->vatRate('SA'));

        $books = $this->category('books');
        $this->postJson('/api/v1/admin/tax-rules', [
            'country' => 'AE', 'tax_type' => 'vat', 'category_id' => $books->id, 'rate_percent' => '0', 'effective_from' => '2026-01-01',
        ], $this->asStaff(['admin']))->assertCreated();

        $this->assertSame('0.0000', $tax->vatRate('AE', [$books->id]));
        $this->assertSame('5.0000', $tax->vatRate('AE', ['some-other-category']));
    }

    public function test_duty_is_zero_under_de_minimis_and_applies_above_it(): void
    {
        $tax = app(TaxRules::class);

        $low = $tax->dutyForShipment('AE', [new DutyLine('620442', '400.00'), new DutyLine(null, '500.00')], 'AED');
        $this->assertTrue($low->deMinimisApplied);
        $this->assertSame('0.00', $low->amount);

        $high = $tax->dutyForShipment('AE', [new DutyLine('620442', '1500.00')], 'AED');
        $this->assertFalse($high->deMinimisApplied);
        $this->assertSame('75.00', $high->amount);
    }

    public function test_hs_specific_customs_rules_take_precedence(): void
    {
        $this->postJson('/api/v1/admin/customs-rules', [
            'country' => 'SA', 'hs_code_prefix' => '6204', 'duty_percent' => '12', 'effective_from' => '2026-01-01',
        ], $this->asStaff(['admin']))->assertCreated();

        $duty = app(TaxRules::class)->dutyForShipment('SA', [new DutyLine('620442', '2000.00'), new DutyLine('420221', '1000.00')], 'SAR');
        $this->assertSame('290.00', $duty->amount); // 12% of 2000 + 5% of 1000
    }

    public function test_shipping_bands_and_free_threshold(): void
    {
        $q = app(ShippingQuoter::class);

        $this->assertSame('15.00', $q->option('AE', 'standard', '100.00', 1500)->amount);
        $this->assertSame('25.00', $q->option('AE', 'standard', '100.00', 3000)->amount);
        $this->assertSame('40.00', $q->option('AE', 'standard', '100.00', 9000)->amount);
        $free = $q->option('AE', 'standard', '250.00', 1500);
        $this->assertTrue($free->free);
        $this->assertSame('0.00', $free->amount);
        $this->assertSame('35.00', $q->option('AE', 'express', '250.00', 1500)->amount);
        $this->assertSame('SAR', $q->option('SA', 'standard', '10.00', 100)->currency);
    }

    public function test_tax_configuration_requires_countries_permission(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/admin/tax-rules', $this->asStaff(['finance'])), 403, 'FORBIDDEN');
    }
}
