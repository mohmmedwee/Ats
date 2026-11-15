<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Pricing\Application\Repricer;
use App\Modules\Suppliers\Infrastructure\Models\SupplierVariantMapping;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class ProductOptionsTest extends TestCase
{
    use CatalogFixtures;

    public function test_each_option_has_its_own_price_from_its_supplier_cost(): void
    {
        ['product' => $p, 'variant' => $black, 'supplierProduct' => $sp] = $this->sellableProduct(['AE']);
        $black->update(['attributes' => ['color' => 'Black', 'size' => 'M']]);
        $navy = ProductVariant::query()->create(['product_id' => $p->id, 'sku' => 'NAVY-XL', 'attributes' => ['color' => 'Navy', 'size' => 'XL']]);
        SupplierVariantMapping::query()->create(['supplier_product_id' => $sp->id, 'variant_id' => $navy->id, 'external_variant_id' => 'EV-NAVY', 'cost_price' => '30.00']);
        app(Repricer::class)->reprice($p->id, notify: false);
        app(ListingRefresher::class)->refresh($p->id);

        $variants = collect($this->getJson("/api/v1/products/{$p->slug}", ['X-Country' => 'AE'])->assertOk()->json('data.variants'))->keyBy('sku');
        $this->assertEquals(['color' => 'Navy', 'size' => 'XL'], $variants['NAVY-XL']['attributes']);
        $this->assertGreaterThan((float) $variants[$black->sku]['price']['amount'], (float) $variants['NAVY-XL']['price']['amount']);

        $admin = collect($this->getJson("/api/v1/admin/products/{$p->id}", $this->asStaff(['catalog_manager']))->assertOk()->json('data.variants'))->keyBy('sku');
        $this->assertSame($variants['NAVY-XL']['price']['amount'], $admin['NAVY-XL']['prices']['AE']['amount']);
    }

    public function test_related_products_prefer_the_same_category_and_exclude_the_product(): void
    {
        $dresses = $this->category('dresses');
        $shoes = $this->category('shoes');
        ['product' => $p] = $this->sellableProduct(['AE'], ['category_id' => $dresses->id]);
        ['product' => $same] = $this->sellableProduct(['AE'], ['category_id' => $dresses->id]);
        ['product' => $other] = $this->sellableProduct(['AE'], ['category_id' => $shoes->id]);

        $ids = array_column($this->getJson("/api/v1/products/{$p->id}/related", ['X-Country' => 'AE'])->assertOk()->json('data'), 'id');
        $this->assertSame($same->id, $ids[0]);
        $this->assertContains($other->id, $ids);
        $this->assertNotContains($p->id, $ids);
    }
}
