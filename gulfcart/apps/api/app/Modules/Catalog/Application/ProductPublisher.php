<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Events\ProductStatusChanged;
use App\Modules\Catalog\Infrastructure\Models\Brand;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/** Moves products through the review lifecycle and enforces publishing requirements. */
final class ProductPublisher
{
    public function __construct(private readonly ListingRefresher $listing, private readonly AuditLogger $audit) {}

    public function transition(Product $product, string $to): Product
    {
        $from = $product->status;
        ProductStatus::assertTransition($from, $to);

        if ($to === ProductStatus::PUBLISHED) {
            $problems = $this->publishProblems($product);
            if ($problems !== []) {
                throw new ApiException('PRODUCT_NOT_PUBLISHABLE', 422, ['problems' => $problems]);
            }
        }

        DB::transaction(function () use ($product, $to): void {
            $product->status = $to;
            if ($to === ProductStatus::PUBLISHED) {
                $product->published_at = now();
            }
            $product->save();
        });

        $this->listing->refresh($product->id);
        $this->audit->record('product.status_changed', 'product', $product->id, ['status' => $from], ['status' => $to]);
        event(new ProductStatusChanged($product->id, $from, $to));

        return $product;
    }

    /** @return list<string> */
    public function publishProblems(Product $product): array
    {
        $product->loadMissing(['images', 'variants', 'availability']);
        $problems = [];

        if (blank($product->title['en'] ?? null) || blank($product->title['ar'] ?? null)) {
            $problems[] = 'TITLE_TRANSLATION_MISSING';
        }
        if (! $product->variants->contains(fn ($v) => $v->is_active)) {
            $problems[] = 'NO_ACTIVE_VARIANT';
        }
        if (! $product->images->contains(fn ($i) => $i->isDisplayable())) {
            $problems[] = 'NO_APPROVED_IMAGE';
        }
        if (! $product->availability->contains(fn ($a) => $a->is_available)) {
            $problems[] = 'NO_COUNTRY_AVAILABILITY';
        }
        // Electronics, cosmetics and similar categories only sell brands we are authorized for
        // (counterfeits are seized by customs and expose the seller).
        $category = $product->category_id ? Category::query()->find($product->category_id) : null;
        if ($category?->requiresAuthorizedBrand()) {
            $brand = $product->brand_id ? Brand::query()->find($product->brand_id) : null;
            if ($brand === null || ! $brand->is_authorized) {
                $problems[] = 'BRAND_NOT_AUTHORIZED';
            }
        }

        return $problems;
    }
}
