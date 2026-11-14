<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogWriter;
use App\Modules\Catalog\Domain\ProductDraft;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Jobs\FetchProductImage;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentCatalogWriter implements CatalogWriter
{
    public function __construct(private readonly CountryDirectory $countries, private readonly ListingRefresher $listing) {}

    public static function slugFor(string $title): string
    {
        $base = Str::slug($title) ?: 'product';

        return Str::limit($base, 200, '').'-'.Str::lower(Str::random(6));
    }

    public function createProduct(ProductDraft $draft): string
    {
        return DB::transaction(function () use ($draft): string {
            $product = Product::query()->create($this->attributes($draft) + [
                'slug' => self::slugFor((string) ($draft->title['en'] ?? $draft->title['ar'] ?? 'product')),
                'status' => ProductStatus::DRAFT,
            ]);
            $this->syncCountries($product->id, $draft->countryCodes);

            return $product->id;
        });
    }

    public function updateDraftProduct(string $productId, ProductDraft $draft): void
    {
        $product = Product::query()->findOrFail($productId);
        // Feeds never overwrite content a human has reviewed or published.
        if (in_array($product->status, [ProductStatus::DRAFT], true)) {
            $product->update($this->attributes($draft));
        }
        $this->syncCountries($productId, $draft->countryCodes);
    }

    public function upsertVariant(string $productId, string $sku, array $attributes, ?int $weightGrams = null): string
    {
        $existing = ProductVariant::query()->where('sku', $sku)->first();
        if ($existing !== null) {
            if ($existing->product_id !== $productId) {
                throw new ApiException('CONFLICT', 409, ['sku' => $sku], "SKU {$sku} belongs to another product.");
            }
            $existing->update(['attributes' => $attributes, 'weight_grams' => $weightGrams]);

            return $existing->id;
        }

        return ProductVariant::query()->create([
            'product_id' => $productId, 'sku' => $sku, 'attributes' => $attributes,
            'weight_grams' => $weightGrams, 'is_active' => true,
        ])->id;
    }

    public function addImageFromUrl(string $productId, string $url, bool $rightsApproved, ?string $rightsBasis): void
    {
        if (ProductImage::query()->where('product_id', $productId)->where('source_url', $url)->exists()) {
            return;
        }

        $image = ProductImage::query()->create([
            'product_id' => $productId,
            'source_url' => $url,
            'rights_status' => $rightsApproved ? 'approved' : 'pending',
            'rights_basis' => $rightsBasis,
            'sort_order' => (int) ProductImage::query()->where('product_id', $productId)->max('sort_order') + 1,
        ]);

        FetchProductImage::dispatch($image->id);
    }

    /** @return array<string, mixed> */
    private function attributes(ProductDraft $draft): array
    {
        $categoryId = $draft->categorySlug !== null
            ? Category::query()->where('slug', $draft->categorySlug)->value('id')
            : null;

        return [
            'title' => $draft->title,
            'description' => $draft->description,
            'category_id' => $categoryId,
            'brand' => $draft->brand,
            'weight_grams' => $draft->weightGrams,
            'hs_code' => $draft->hsCode,
        ];
    }

    /** @param list<string> $codes */
    private function syncCountries(string $productId, array $codes): void
    {
        foreach ($codes as $code) {
            $country = $this->countries->find($code);
            if ($country === null) {
                continue;
            }
            DB::table('product_country_availability')->insertOrIgnore([
                'product_id' => $productId, 'country_id' => $country->id, 'is_available' => true, 'updated_at' => now(),
            ]);
        }
        $this->listing->refresh($productId);
    }
}
