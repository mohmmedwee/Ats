<?php

namespace Tests\Support;

use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Countries\Infrastructure\Models\Country;
use App\Modules\Pricing\Application\Repricer;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use App\Modules\Suppliers\Infrastructure\Models\SupplierVariantMapping;
use Illuminate\Support\Str;

/** Builds catalog and supplier data directly (bypassing the API) for tests. */
trait CatalogFixtures
{
    protected function category(string $slug = 'women', ?string $parentId = null): Category
    {
        return Category::query()->create(['slug' => $slug, 'name' => ['en' => ucfirst($slug), 'ar' => 'قسم '.$slug], 'parent_id' => $parentId]);
    }

    /** @param list<string> $countries */
    protected function supplier(array $countries = ['AE', 'SA'], array $overrides = [], array $authorization = []): Supplier
    {
        $supplier = Supplier::query()->create($overrides + [
            'name' => 'Supplier '.Str::random(4),
            'code' => 'sup-'.Str::lower(Str::random(6)),
            'connector_type' => 'mock',
            'base_currency' => 'USD',
            'commercial_authorization_status' => 'approved',
            'is_active' => true,
            'metadata' => ['shipping_cost_per_unit' => '12.00', 'delivery_min_days' => 7, 'delivery_max_days' => 12],
        ]);
        $supplier->authorizations()->create($authorization + [
            'agreement_reference' => 'AGR-'.Str::random(5),
            'allows_resale' => true, 'allows_api_purchasing' => true,
            'allows_direct_shipping' => true, 'allows_image_use' => true,
            'countries' => '{'.implode(',', $countries).'}',
            'valid_from' => now()->subMonth()->toDateString(),
        ]);

        return $supplier;
    }

    /**
     * A published product with one approved image, available (and sold) in the given countries.
     *
     * @param  list<string>  $countries
     * @return array{product: Product, variant: ProductVariant, supplier: Supplier, supplierProduct: SupplierProduct}
     */
    protected function sellableProduct(array $countries = ['AE', 'SA'], array $options = []): array
    {
        $supplier = $options['supplier'] ?? $this->supplier($options['supplier_countries'] ?? $countries);
        $product = Product::query()->create([
            'title' => $options['title'] ?? ['en' => 'Summer Dress', 'ar' => 'فستان صيفي'],
            'slug' => $options['slug'] ?? 'summer-dress-'.Str::lower(Str::random(5)),
            'status' => $options['status'] ?? 'published',
            'category_id' => $options['category_id'] ?? null,
            'brand' => 'Acme',
            'attributes' => [],
            'weight_grams' => 400,
            'hs_code' => '620442',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id, 'sku' => 'SKU-'.Str::upper(Str::random(8)), 'attributes' => ['size' => 'M', 'color' => 'Black'],
        ]);
        ProductImage::query()->create(['product_id' => $product->id, 'storage_path' => "products/{$product->id}/a.jpg", 'rights_status' => 'approved', 'rights_basis' => 'own_photo']);

        foreach ($countries as $code) {
            $country = Country::query()->where('code', $code)->firstOrFail();
            $product->availability()->create(['country_id' => $country->id, 'is_available' => true, 'estimated_delivery_min_days' => 7, 'estimated_delivery_max_days' => 12]);
        }

        $sp = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'product_id' => $product->id,
            'external_product_id' => 'EXT-'.Str::random(8), 'cost_price' => $options['cost'] ?? '24.00',
            'cost_currency' => 'USD', 'stock_status' => $options['stock'] ?? 'in_stock', 'priority' => 100,
        ]);
        SupplierVariantMapping::query()->create(['supplier_product_id' => $sp->id, 'variant_id' => $variant->id, 'external_variant_id' => 'EV-'.Str::random(6)]);

        app(Repricer::class)->reprice($product->id, notify: false);
        app(ListingRefresher::class)->refresh($product->id);

        return ['product' => $product->refresh(), 'variant' => $variant, 'supplier' => $supplier, 'supplierProduct' => $sp];
    }
}
