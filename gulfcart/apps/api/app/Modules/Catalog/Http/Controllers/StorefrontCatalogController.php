<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Contracts\CatalogPricing;
use App\Modules\Catalog\Contracts\CatalogStock;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Countries\Domain\CountryData;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing catalog. Only products that are published and sellable in the
 * request country are visible. Supplier data, costs and margins are never exposed.
 */
final class StorefrontCatalogController
{
    public function __construct(private readonly CatalogPricing $pricing, private readonly CatalogStock $stock) {}

    public function categories(Request $request): JsonResponse
    {
        $all = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('slug')->get();
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 'root');
        $build = function (string $parent) use (&$build, $byParent): array {
            return ($byParent[$parent] ?? collect())->map(fn ($c) => [
                'id' => $c->id, 'slug' => $c->slug, 'name' => Translations::pick($c->name), 'children' => $build($c->id),
            ])->values()->all();
        };

        return ApiResponse::success($build('root'));
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:180'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->listing($request, $request->query('category'), $request->query('q'));
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return $this->listing($request, $request->query('category'), $request->query('q'));
    }

    public function byCategory(Request $request, string $slug): JsonResponse
    {
        Category::query()->where('slug', $slug)->where('is_active', true)->firstOr(fn () => throw new ApiException('NOT_FOUND', 404));

        return $this->listing($request, $slug, $request->query('q'));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $country = $this->country($request);
        $product = $this->visible($country)->with(['images', 'variants', 'category'])->where('products.slug', $slug)
            ->firstOr(fn () => throw new ApiException('NOT_FOUND', 404));

        return ApiResponse::success($this->present($product, $country, true));
    }

    public function variants(Request $request, string $id): JsonResponse
    {
        $country = $this->country($request);
        $product = $this->visible($country)->with('variants')->where('products.id', $id)
            ->firstOr(fn () => throw new ApiException('NOT_FOUND', 404));

        return ApiResponse::success($this->variantRows($product, $country));
    }

    public function availability(Request $request, string $id): JsonResponse
    {
        $country = $this->country($request);
        $product = $this->visible($country)->with('variants')->where('products.id', $id)
            ->firstOr(fn () => throw new ApiException('NOT_FOUND', 404));
        $rows = $this->variantRows($product, $country);

        return ApiResponse::success([
            'product_id' => $product->id,
            'country' => $country->code,
            'availability' => $this->aggregate(array_column($rows, 'availability')),
            'delivery_estimate' => $this->estimate($product),
            'variants' => array_map(fn ($r) => ['id' => $r['id'], 'availability' => $r['availability']], $rows),
        ]);
    }

    private function listing(Request $request, ?string $categorySlug, ?string $q): JsonResponse
    {
        $country = $this->country($request);
        $query = $this->visible($country)->with(['images', 'category']);

        if ($categorySlug !== null && $categorySlug !== '') {
            $ids = $this->categoryWithDescendants($categorySlug);
            $query->whereIn('products.category_id', $ids);
        }
        if ($q !== null && trim($q) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($q)).'%';
            $query->whereRaw("(coalesce(products.title->>'en','') || ' ' || coalesce(products.title->>'ar','') || ' ' || coalesce(products.brand,'')) ILIKE ?", [$term]);
        }

        if (is_numeric($max = $request->query('price_max'))) {
            $query->where('pca.from_price', '<=', $max);
        }

        match ($request->query('sort', 'newest')) {
            'price_asc' => $query->orderBy('pca.from_price')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('pca.from_price')->orderBy('products.id'),
            default => $query->orderByDesc('products.published_at')->orderBy('products.id'),
        };

        $page = $query->cursorPaginate($request->integer('limit', 24));

        return ApiResponse::cursor($page, $page->getCollection()->map(fn (Product $p) => $this->present($p, $country, false))->all());
    }

    /** @return Builder<Product> */
    private function visible(CountryData $country): Builder
    {
        return Product::query()
            ->select('products.*', 'pca.from_price', 'pca.from_price_currency', 'pca.estimated_delivery_min_days', 'pca.estimated_delivery_max_days')
            ->join('product_country_availability as pca', function ($j) use ($country) {
                $j->on('pca.product_id', '=', 'products.id')->where('pca.country_id', '=', $country->id);
            })
            ->where('products.status', ProductStatus::PUBLISHED)
            ->where('pca.is_available', true)
            ->where('pca.is_sellable', true);
    }

    /** @return array<string, mixed> */
    private function present(Product $p, CountryData $country, bool $detailed): array
    {
        $images = $p->images->filter(fn ($i) => $i->isDisplayable())->values();
        $data = [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => Translations::pick($p->title),
            'brand' => $p->brand,
            'category' => $p->category ? ['slug' => $p->category->slug, 'name' => Translations::pick($p->category->name)] : null,
            'price' => $p->getAttribute('from_price') !== null
                ? ['amount' => (string) $p->getAttribute('from_price'), 'currency' => (string) $p->getAttribute('from_price_currency')]
                : null,
            'image' => $images->first()?->publicUrl(),
            'delivery_estimate' => $this->estimate($p),
        ];

        if ($detailed) {
            $variants = $this->variantRows($p, $country);
            $data += [
                'description' => Translations::pick($p->description),
                'images' => $images->map(fn ($i) => ['url' => $i->publicUrl(), 'alt' => Translations::pick($i->alt_text), 'variant_id' => $i->variant_id])->all(),
                'attributes' => (object) $p->attributes,
                'availability' => $this->aggregate(array_column($variants, 'availability')),
                'variants' => $variants,
                'prices_include_tax' => true,
            ];
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function variantRows(Product $p, CountryData $country): array
    {
        $variants = $p->variants->filter(fn ($v) => $v->is_active)->values();
        $ids = $variants->pluck('id')->all();
        $prices = $this->pricing->variantPrices($ids, $country->code);
        $stock = $this->stock->variantAvailability($ids, $country->code);

        return $variants->map(fn ($v) => [
            'id' => $v->id,
            'sku' => $v->sku,
            'attributes' => (object) $v->attributes,
            'price' => $prices[$v->id] ?? null,
            'availability' => $stock[$v->id] ?? 'out_of_stock',
            'purchasable' => isset($prices[$v->id]) && isset($stock[$v->id]) && $stock[$v->id] !== 'out_of_stock',
        ])->all();
    }

    /** @param list<string> $states */
    private function aggregate(array $states): string
    {
        return in_array('in_stock', $states, true) ? 'in_stock' : (in_array('low_stock', $states, true) ? 'low_stock' : 'out_of_stock');
    }

    /** @return array{min_days: int, max_days: int}|null */
    private function estimate(Product $p): ?array
    {
        $min = $p->getAttribute('estimated_delivery_min_days');
        $max = $p->getAttribute('estimated_delivery_max_days');

        return $min !== null && $max !== null ? ['min_days' => (int) $min, 'max_days' => (int) $max] : null;
    }

    /** @return list<string> */
    private function categoryWithDescendants(string $slug): array
    {
        $root = Category::query()->where('slug', $slug)->value('id');
        if ($root === null) {
            return [];
        }
        $ids = [$root];
        $frontier = [$root];
        while ($frontier !== []) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->where('is_active', true)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function country(Request $request): CountryData
    {
        /** @var CountryData */
        return $request->attributes->get('country');
    }
}
