<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Brand;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Brand pages for the storefront, brand management and category images for admin (catalog.manage). */
final class BrandController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Active brands with at least one published product, A–Z; featured first when ?featured=1. */
    public function index(Request $request): JsonResponse
    {
        $counts = DB::table('products')->where('status', ProductStatus::PUBLISHED)->whereNotNull('brand_id')
            ->groupBy('brand_id')->selectRaw('brand_id, count(*) as n')->pluck('n', 'brand_id');
        $brands = Brand::query()->where('is_active', true)->whereIn('id', $counts->keys())
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->orderBy('sort_order')->get()
            ->sortBy(fn (Brand $b) => mb_strtolower((string) Translations::pick($b->name)))->values();

        return ApiResponse::success($brands->map(fn (Brand $b) => $this->public($b) + ['product_count' => (int) ($counts[$b->id] ?? 0)])->all());
    }

    public function show(string $slug): JsonResponse
    {
        $brand = Brand::query()->where('slug', $slug)->where('is_active', true)->first() ?? throw new ApiException('NOT_FOUND', 404);

        return ApiResponse::success($this->public($brand) + ['description' => Translations::pick($brand->description)]);
    }

    public function adminIndex(): JsonResponse
    {
        $counts = DB::table('products')->whereNotNull('brand_id')->groupBy('brand_id')->selectRaw('brand_id, count(*) as n')->pluck('n', 'brand_id');

        return ApiResponse::success(Brand::query()->orderBy('sort_order')->orderBy('slug')->get()
            ->map(fn (Brand $b) => $this->admin($b) + ['product_count' => (int) ($counts[$b->id] ?? 0)])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $brand = Brand::query()->create($this->validated($request, null));
        $this->audit->record('brand.created', 'brand', $brand->id, null, ['slug' => $brand->slug, 'authorized' => $brand->is_authorized]);

        return ApiResponse::success($this->admin($brand), 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $data = $this->validated($request, $brand);
        $old = $brand->only(array_keys($data));
        $brand->update($data);
        if (isset($data['name']['en'])) {
            DB::table('products')->where('brand_id', $brand->id)->update(['brand' => $data['name']['en']]);
        }
        $this->audit->record('brand.updated', 'brand', $brand->id, $old, $data);

        return ApiResponse::success($this->admin($brand));
    }

    public function logo(Request $request, Brand $brand): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048']]);
        $brand->update(['logo_path' => $this->store_file($request, "brands/{$brand->id}", $brand->logo_path)]);

        return ApiResponse::success($this->admin($brand));
    }

    public function categoryImage(Request $request, Category $category): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096']]);
        $category->update(['image_path' => $this->store_file($request, "categories/{$category->id}", $category->image_path)]);
        $this->audit->record('category.image_updated', 'category', $category->id);

        return ApiResponse::success(['id' => $category->id, 'image_url' => $category->imageUrl()]);
    }

    private function store_file(Request $request, string $dir, ?string $old): string
    {
        $disk = Storage::disk((string) config('gulfcart.catalog_disk', 'public'));
        $path = $request->file('file')->store($dir, (string) config('gulfcart.catalog_disk', 'public'));
        if ($old && $old !== $path) {
            $disk->delete($old);
        }

        return (string) $path;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Brand $brand): array
    {
        $creating = $brand === null;
        if ($creating && ! $request->filled('slug') && is_string($request->input('name.en'))) {
            $request->merge(['slug' => Str::slug((string) $request->input('name.en'))]);
        }

        return $request->validate(Translations::rules('name', $creating, false, 120) + Translations::rules('description', false, false, 2000) + [
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('brands', 'slug')->ignore($brand?->id)],
            'is_authorized' => ['sometimes', 'boolean'],
            'authorization_note' => ['nullable', 'string', 'max:255'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,100000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function public(Brand $b): array
    {
        return ['id' => $b->id, 'slug' => $b->slug, 'name' => Translations::pick($b->name), 'logo_url' => $b->logoUrl(), 'authorized' => $b->is_authorized, 'featured' => $b->is_featured];
    }

    /** @return array<string, mixed> */
    private function admin(Brand $b): array
    {
        return $b->only(['id', 'slug', 'name', 'description', 'is_authorized', 'authorization_note', 'is_featured', 'is_active', 'sort_order']) + ['logo_url' => $b->logoUrl()];
    }
}
