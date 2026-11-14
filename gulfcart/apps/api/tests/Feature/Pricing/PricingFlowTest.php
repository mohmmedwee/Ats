<?php

namespace Tests\Feature\Pricing;

use App\Modules\Pricing\Application\Repricer;
use App\Modules\Pricing\Infrastructure\Models\ExchangeRate;
use App\Modules\Pricing\Infrastructure\Models\PricingRule;
use App\Modules\Pricing\Infrastructure\Models\ProductPrice;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class PricingFlowTest extends TestCase
{
    use CatalogFixtures;

    public function test_prices_are_computed_per_country_with_their_own_vat(): void
    {
        ['product' => $p, 'variant' => $v] = $this->sellableProduct();

        $prices = ProductPrice::query()->where('variant_id', $v->id)->get()->keyBy('currency');

        $this->assertSame('5.0000', $prices['AED']->vat_rate);
        $this->assertSame('15.0000', $prices['SAR']->vat_rate);
        $this->assertStringEndsWith('.00', (string) $prices['AED']->gross_price);
        $this->assertGreaterThanOrEqual(20.0, (float) $prices['AED']->calculation['marginPercent']);
        $this->assertGreaterThanOrEqual(20.0, (float) $prices['SAR']->calculation['marginPercent']);

        $this->getJson("/api/v1/products/{$p->slug}", ['X-Country' => 'SA'])->assertOk()
            ->assertJsonPath('data.price.currency', 'SAR')
            ->assertJsonPath('data.price.amount', (string) $prices['SAR']->gross_price)
            ->assertJsonPath('data.variants.0.purchasable', true);
    }

    public function test_cheapest_authorized_source_wins(): void
    {
        ['product' => $p, 'variant' => $v, 'supplierProduct' => $expensive] = $this->sellableProduct(['AE'], ['cost' => '30.00']);
        $cheap = $this->supplier(['AE']);
        $sp = $cheap->products()->create(['product_id' => $p->id, 'external_product_id' => 'CHEAP-1', 'cost_price' => '10.00', 'cost_currency' => 'USD', 'stock_status' => 'in_stock']);
        $sp->mappings()->create(['variant_id' => $v->id, 'external_variant_id' => 'CHEAP-1-M']);

        app(Repricer::class)->reprice($p->id);

        $this->assertSame($sp->id, ProductPrice::query()->where('variant_id', $v->id)->value('supplier_product_id'));
    }

    public function test_more_specific_rules_win_and_min_margin_is_strictest(): void
    {
        ['product' => $p, 'variant' => $v] = $this->sellableProduct(['AE']);
        PricingRule::query()->create(['scope' => 'product', 'product_id' => $p->id, 'rule_type' => 'margin_percent', 'value' => '35', 'min_margin_percent' => '30']);

        app(Repricer::class)->reprice($p->id);

        $margin = (float) ProductPrice::query()->where('variant_id', $v->id)->firstOrFail()->calculation['marginPercent'];
        $this->assertGreaterThanOrEqual(35.0, $margin);
    }

    public function test_stale_exchange_rates_take_products_off_sale(): void
    {
        ['product' => $p] = $this->sellableProduct(['AE']);
        ExchangeRate::query()->update(['effective_at' => now()->subDays(3)]);

        app(Repricer::class)->reprice($p->id);

        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('product_country_availability', ['product_id' => $p->id, 'unsellable_reason' => 'NO_PRICE']);

        $this->artisan('fx:refresh --reprice')->assertSuccessful();
        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');
    }

    public function test_supplier_cost_change_reprices_automatically(): void
    {
        ['variant' => $v, 'supplierProduct' => $sp] = $this->sellableProduct(['AE']);
        $before = (float) ProductPrice::query()->where('variant_id', $v->id)->value('gross_price');

        $this->patchJson("/api/v1/admin/supplier-products/{$sp->id}", ['cost_price' => '48.00'], $this->asStaff(['catalog_manager']))->assertOk();

        $this->assertGreaterThan($before, (float) ProductPrice::query()->where('variant_id', $v->id)->value('gross_price'));
    }

    public function test_admin_simulator_matches_the_documented_example(): void
    {
        $this->postJson('/api/v1/admin/pricing/simulate', [
            'unit_cost' => '24', 'shipping_cost' => '12', 'cost_currency' => 'USD', 'sale_currency' => 'AED',
            'exchange_rate' => '3.6725', 'operational_allocation' => '14.69', 'vat_percent' => '5',
            'payment_fee_percent' => '2.9', 'rule_type' => 'margin_percent', 'value' => '20',
        ], $this->asStaff(['finance']))->assertOk()
            ->assertJsonPath('data.grossPrice', '201.00')
            ->assertJsonPath('data.marginPercent', '20.22');
    }

    public function test_pricing_rules_require_pricing_permission_and_trigger_repricing(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $this->assertErrorCode($this->getJson('/api/v1/admin/pricing-rules', $this->asStaff(['catalog_manager'])), 403, 'FORBIDDEN');
        $this->resetAuth();
        $before = (float) ProductPrice::query()->where('variant_id', $v->id)->value('gross_price');

        $this->postJson('/api/v1/admin/pricing-rules', ['scope' => 'country', 'country' => 'AE', 'rule_type' => 'margin_percent', 'value' => '40'], $this->asStaff(['finance']))
            ->assertCreated();

        $this->assertGreaterThan($before, (float) ProductPrice::query()->where('variant_id', $v->id)->value('gross_price'));
    }
}
