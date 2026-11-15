<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Contracts\CatalogPricing;
use App\Modules\Catalog\Contracts\CatalogStock;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Brand;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Countries\Domain\CountryData;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                'id' => $c->id, 'slug' => $c->slug, 'name' => Translations::pick($c->name), 'image_url' => $c->imageUrl(), 'children' => $build($c->id),
            ])->values()->all();
        };

        return ApiResponse::success($build('root'));
    }

    /** Filters for the listing being shown: brands with counts and the price range. */
    public function facets(Request $request): JsonResponse
    {
        $country = $this->country($request);
        $query = $this->visible($country);
        if (is_string($slug = $request->query('category')) && $slug !== '') {
            $query->whereIn('products.category_id', $this->categoryWithDescendants($slug));
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($q)).'%';
            $query->whereRaw("(coalesce(products.title->>'en','') || ' ' || coalesce(products.title->>'ar','') || ' ' || coalesce(products.brand,'')) ILIKE ?", [$term]);
        }
        $ids = (clone $query)->pluck('products.id');
        $counts = Product::query()->whereIn('id', $ids)->whereNotNull('brand_id')->groupBy('brand_id')->selectRaw('brand_id, count(*) as n')->pluck('n', 'brand_id');
        $brands = Brand::query()->whereIn('id', $counts->keys())->where('is_active', true)->get()
            ->map(fn (Brand $b) => ['slug' => $b->slug, 'name' => Translations::pick($b->name), 'count' => (int) $counts[$b->id]])
            ->sortByDesc('count')->values()->all();
        $range = DB::query()->fromSub((clone $query)->select('pca.from_price'), 't')->selectRaw('min(from_price) as min, max(from_price) as max')->first();

        return ApiResponse::success([
            'brands' => $brands,
            'price' => ['min' => $range?->min !== null ? (string) $range->min : null, 'max' => $range?->max !== null ? (string) $range->max : null, 'currency' => $country->currency],
        ]);
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
        $product = $this->visible($country)->with(['images', 'variants', 'category', 'brandRecord'])->where('products.slug', $slug)
            ->firstOr(fn () => throw new ApiException('NOT_FOUND', 404));

        return ApiResponse::success($this->present($product, $country, true));
    }

    /**
     * "You may also like": same category, then the parent category's other children, then the same
     * brand, ordered by closeness in price; topped up with new arrivals.
     */
    public function related(Request $request, string $id): JsonResponse
    {
        $country = $this->country($request);
        $limit = min(24, max(1, $request->integer('limit', 12)));
        $product = $this->visible($country)->where('products.id', $id)->first() ?? throw new ApiException('NOT_FOUND', 404);
        $price = (float) $product->getAttribute('from_price');
        $picked = [];
        $collect = function ($query) use (&$picked, $limit, $id, $price) {
            if (count($picked) >= $limit) {
                return;
            }
            $query->where('products.id', '<>', $id)->whereNotIn('products.id', array_keys($picked))
                ->orderByRaw('abs(pca.from_price - ?)', [$price])->limit($limit - count($picked))
                ->with(['images', 'category', 'brandRecord'])->get()->each(function (Product $p) use (&$picked) {
                    $picked[$p->id] = $p;
                });
        };

        if ($product->category_id !== null) {
            $collect($this->visible($country)->where('products.category_id', $product->category_id));
            $parent = Category::query()->whereKey($product->category_id)->value('parent_id');
            if ($parent !== null) {
                $collect($this->visible($country)->whereIn('products.category_id', $this->categoryWithDescendants((string) Category::query()->whereKey($parent)->value('slug'))));
            }
        }
        if ($product->brand) {
            $collect($this->visible($country)->where('products.brand', $product->brand));
        }
        if (count($picked) < $limit) {
            $this->visible($country)->where('products.id', '<>', $id)->whereNotIn('products.id', array_keys($picked))
                ->orderByDesc('products.published_at')->limit($limit - count($picked))->with(['images', 'category', 'brandRecord'])->get()
                ->each(function (Product $p) use (&$picked) {
                    $picked[$p->id] = $p;
                });
        }

        return ApiResponse::success(array_values(array_map(fn (Product $p) => $this->present($p, $country, false), $picked)));
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
        $query = $this->visible($country)->with(['images', 'category', 'brandRecord']);

        if ($categorySlug !== null && $categorySlug !== '') {
            $ids = $this->categoryWithDescendants($categorySlug);
            $query->whereIn('products.category_id', $ids);
        }
        if (is_string($brand = $request->query('brand')) && $brand !== '') {
            $query->whereIn('products.brand_id', Brand::query()->whereIn('slug', array_slice(explode(',', $brand), 0, 20))->pluck('id'));
        }
        if (is_string($ids = $request->query('ids')) && $ids !== '') {
            $list = array_slice(array_filter(explode(',', $ids), fn ($id) => Str::isUuid($id)), 0, 100);
            $query->whereIn('products.id', $list === [] ? ['00000000-0000-0000-0000-000000000000'] : $list);
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

    /** The product's category size guide, or the nearest parent's. */
    private function sizeGuide(?Category $category): ?string
    {
        for ($guard = 0; $category !== null && $guard < 10; $guard++) {
            $text = Translations::pick($category->size_guide);
            if ($text !== null && trim($text) !== '') {
                return $text;
            }
            $category = $category->parent_id ? Category::query()->find($category->parent_id) : null;
        }

        return null;
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
            'brand' => $p->brandRecord ? Translations::pick($p->brandRecord->name) : $p->brand,
            'brand_slug' => $p->brandRecord?->slug,
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
                'size_guide' => $this->sizeGuide($p->category),
                'specifications' => Translations::pick($p->specifications),
                'warranty' => Translations::pick($p->warranty),
                'brand_info' => $p->brandRecord ? ['slug' => $p->brandRecord->slug, 'name' => Translations::pick($p->brandRecord->name), 'logo_url' => $p->brandRecord->logoUrl(), 'authorized' => $p->brandRecord->is_authorized] : null,
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
