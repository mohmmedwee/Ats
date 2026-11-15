<?php

namespace Tests\Feature\Pricing;

use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Pricing\Infrastructure\Models\FlashSale;
use App\Modules\Pricing\Infrastructure\Models\ProductPrice;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class FlashSaleTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    private const AE = ['X-Country' => 'AE'];

    private function regular(string $variantId): string
    {
        return (string) ProductPrice::query()->where('variant_id', $variantId)->value('gross_price');
    }

    public function test_sale_price_is_shown_charged_and_recorded_on_the_order(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->sellableProduct(['AE'], ['cost' => '10.00']);
        ['product' => $other] = $this->sellableProduct(['AE'], ['cost' => '10.00']);
        $regular = $this->regular($variant->id);

        $this->postJson('/api/v1/admin/flash-sales', [
            'name' => ['en' => 'Weekend sale', 'ar' => 'تخفيضات الويكند'], 'percent_off' => 20, 'scope' => 'product', 'product_id' => $product->id,
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(),
        ], $this->asStaff(['finance']))->assertCreated()->assertJsonPath('data.status', 'running');

        $expected = number_format(round((float) $regular * 0.8, 2), 2, '.', '');
        $this->getJson("/api/v1/products/{$product->slug}", self::AE)->assertOk()
            ->assertJsonPath('data.price.amount', $expected)
            ->assertJsonPath('data.compare_at_price.amount', $regular)
            ->assertJsonPath('data.variants.0.price.amount', $expected)
            ->assertJsonPath('data.variants.0.compare_at_price.amount', $regular);
        $this->assertNotNull($this->getJson("/api/v1/products/{$product->slug}", self::AE)->json('data.sale_ends_at'));

        $listed = collect($this->getJson('/api/v1/products?sale=1', self::AE)->assertOk()->json('data'));
        $this->assertSame([$product->id], $listed->pluck('id')->all());
        $this->assertNotContains($other->id, $listed->pluck('id'));
        $this->getJson('/api/v1/flash-sales/current', self::AE + ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.0.name', 'تخفيضات الويكند')->assertJsonPath('data.0.percent_off', '20.00');

        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$variant, 2]], $auth);
        $item = $order->items()->firstOrFail();
        $this->assertSame($expected, (string) $item->unit_gross_price);
        $this->assertStringContainsString('"regular_gross":"'.$regular.'"', (string) json_encode($item->pricing_snapshot));
    }

    public function test_never_sells_below_cost_and_ignores_ended_or_paused_sales(): void
    {
        $parent = Category::query()->create(['slug' => 'women-'.Str::random(4), 'name' => ['en' => 'Women'], 'is_active' => true]);
        $child = Category::query()->create(['slug' => 'dresses-'.Str::random(4), 'name' => ['en' => 'Dresses'], 'parent_id' => $parent->id, 'is_active' => true]);
        ['product' => $product, 'variant' => $variant] = $this->sellableProduct(['AE'], ['category_id' => $child->id]);
        $regular = $this->regular($variant->id);
        $row = ProductPrice::query()->where('variant_id', $variant->id)->firstOrFail();

        FlashSale::query()->create(['name' => ['en' => 'Old'], 'percent_off' => 50, 'scope' => 'all', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        FlashSale::query()->create(['name' => ['en' => 'Paused'], 'percent_off' => 50, 'scope' => 'all', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => false]);
        $this->getJson("/api/v1/products/{$product->slug}", self::AE)->assertJsonPath('data.price.amount', $regular)->assertJsonPath('data.compare_at_price', null);

        // 90% off the parent category: clamped so the net price still covers the variable cost.
        FlashSale::query()->create(['name' => ['en' => 'Mega'], 'percent_off' => 90, 'scope' => 'category', 'category_id' => $parent->id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $price = $this->getJson("/api/v1/products/{$product->slug}", self::AE)->assertOk()->json('data.price.amount');
        $this->assertLessThan((float) $regular, (float) $price);
        $net = (float) $price / (1 + (float) $row->vat_rate / 100);
        $this->assertGreaterThanOrEqual((float) $row->variable_cost, round($net, 2) + 0.01);

        // Other countries untouched when the sale is limited to SA.
        FlashSale::query()->update(['countries' => ['SA']]);
        $this->getJson("/api/v1/products/{$product->slug}", self::AE)->assertJsonPath('data.price.amount', $regular);
    }
}
