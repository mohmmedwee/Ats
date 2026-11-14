<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\ListingRefresher;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class StorefrontCatalogTest extends TestCase
{
    use CatalogFixtures;

    private const FORBIDDEN_KEYS = ['supplier', 'supplier_id', 'cost', 'cost_price', 'margin', 'external_product_id', 'external_variant_id', 'source_url', 'supplier_product_id'];

    public function test_only_sellable_products_are_listed_per_country(): void
    {
        $both = $this->sellableProduct(['AE', 'SA']);
        $aeOnly = $this->sellableProduct(['AE', 'SA'], ['supplier_countries' => ['AE']]);
        $this->sellableProduct(['AE'], ['status' => 'draft']);

        $ae = $this->getJson('/api/v1/products', ['X-Country' => 'AE'])->assertOk()->json('data.*.id');
        $sa = $this->getJson('/api/v1/products', ['X-Country' => 'SA'])->assertOk()->json('data.*.id');

        $this->assertEqualsCanonicalizing([$both['product']->id, $aeOnly['product']->id], $ae);
        $this->assertSame([$both['product']->id], $sa);
    }

    public function test_out_of_stock_or_deactivated_sources_make_products_unsellable(): void
    {
        $this->sellableProduct(['AE'], ['stock' => 'out_of_stock']);
        $x = $this->sellableProduct(['AE']);
        $x['supplier']->update(['is_active' => false]);
        app(ListingRefresher::class)->refresh($x['product']->id);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_responses_never_expose_supplier_or_cost_data(): void
    {
        ['product' => $p] = $this->sellableProduct();

        foreach ([
            $this->getJson('/api/v1/products')->json(),
            $this->getJson("/api/v1/products/{$p->slug}")->json(),
            $this->getJson("/api/v1/products/{$p->id}/variants")->json(),
            $this->getJson("/api/v1/products/{$p->id}/availability")->json(),
        ] as $payload) {
            $this->assertSame([], $this->forbiddenKeys($payload));
        }
    }

    public function test_product_detail_is_localized_and_unpublished_products_are_hidden(): void
    {
        ['product' => $p] = $this->sellableProduct();
        $hidden = $this->sellableProduct(['AE'], ['status' => 'unpublished']);

        $this->getJson("/api/v1/products/{$p->slug}", ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.title', 'فستان صيفي')
            ->assertJsonPath('data.availability', 'in_stock')
            ->assertJsonPath('data.delivery_estimate', ['min_days' => 7, 'max_days' => 12])
            ->assertJsonPath('data.variants.0.attributes', ['size' => 'M', 'color' => 'Black']);

        $this->assertErrorCode($this->getJson("/api/v1/products/{$hidden['product']->slug}"), 404, 'NOT_FOUND');
    }

    public function test_search_and_category_filter_include_subcategories(): void
    {
        $women = $this->category('women');
        $dresses = $this->category('dresses', $women->id);
        $dress = $this->sellableProduct(['AE'], ['category_id' => $dresses->id]);
        $this->sellableProduct(['AE'], ['title' => ['en' => 'Leather Bag', 'ar' => 'حقيبة جلد']]);

        $this->getJson('/api/v1/categories/women/products')->assertOk()->assertJsonPath('data.0.id', $dress['product']->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products/search?q=leather')->assertOk()->assertJsonPath('data.0.title', 'Leather Bag')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products/search?q='.urlencode('حقيبة'))->assertOk()->assertJsonCount(1, 'data');
        $this->assertErrorCode($this->getJson('/api/v1/products/search?q=a'), 422, 'VALIDATION_FAILED');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.slug', 'women')->assertJsonPath('data.0.children.0.slug', 'dresses');
    }

    public function test_listing_filters_by_maximum_price(): void
    {
        $cheap = $this->sellableProduct(['AE'], ['cost' => '5.00']);
        $this->sellableProduct(['AE'], ['cost' => '300.00']);
        $cheapPrice = (float) $this->getJson("/api/v1/products/{$cheap['product']->slug}")->json('data.price.amount');

        $this->getJson('/api/v1/products?price_max='.ceil($cheapPrice))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cheap['product']->id);
    }

    public function test_listing_uses_cursor_pagination(): void
    {
        foreach (range(1, 3) as $_) {
            $this->sellableProduct(['AE']);
        }

        $first = $this->getJson('/api/v1/products?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.pagination.next_cursor');
        $this->assertNotNull($cursor);
        $this->getJson('/api/v1/products?limit=2&cursor='.$cursor)->assertOk()->assertJsonCount(1, 'data');
    }

    /** @return list<string> */
    private function forbiddenKeys(mixed $value, string $path = ''): array
    {
        if (! is_array($value)) {
            return [];
        }
        $found = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array($k, self::FORBIDDEN_KEYS, true)) {
                $found[] = "{$path}.{$k}";
            }
            $found = array_merge($found, $this->forbiddenKeys($v, "{$path}.{$k}"));
        }

        return $found;
    }
}
