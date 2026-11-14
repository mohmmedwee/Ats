<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Application\EloquentCatalogWriter;
use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Application\ProductPublisher;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Http\Requests\AvailabilityRequest;
use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Http\Resources\AdminProductResource;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Countries\Contracts\CountryDirectory;
use App\Modules\Identity\Domain\Permissions;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProductController
{
    public function __construct(
        private readonly ProductPublisher $publisher,
        private readonly ListingRefresher $listing,
        private readonly CountryDirectory $countries,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with(['variants', 'images', 'availability'])->latest();
        if (is_string($status = $request->query('status')) && in_array($status, ProductStatus::states(), true)) {
            $query->where('status', $status);
        }
        if (is_string($q = $request->query('q')) && $q !== '') {
            $query->where(fn ($w) => $w->whereRaw("title->>'en' ILIKE ?", ["%{$q}%"])
                ->orWhereRaw("title->>'ar' ILIKE ?", ["%{$q}%"])
                ->orWhereHas('variants', fn ($v) => $v->where('sku', $q)));
        }
        $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));
        $this->shareCountryCodes($request);

        return ApiResponse::paged($page, AdminProductResource::collection($page->getCollection())->resolve($request));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $product = Product::query()->create($data + [
            'slug' => EloquentCatalogWriter::slugFor((string) ($data['title']['en'] ?? 'product')),
            'status' => ProductStatus::DRAFT,
            'attributes' => $data['attributes'] ?? [],
        ]);
        $this->audit->record('product.created', 'product', $product->id, null, $data);

        return $this->show($request, $product, 201);
    }

    public function show(Request $request, Product $product, int $status = 200): JsonResponse
    {
        $product->load(['variants', 'images', 'availability']);
        $this->shareCountryCodes($request);

        return ApiResponse::success((new AdminProductResource($product))->resolve($request), $status);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        if ($product->status === ProductStatus::ARCHIVED) {
            throw new ApiException('ILLEGAL_STATE_TRANSITION', 409, ['entity' => 'product', 'from' => 'archived', 'to' => 'edit']);
        }
        $data = $request->validated();
        $old = $product->only(array_keys($data));
        $product->update($data);
        $this->audit->record('product.updated', 'product', $product->id, $old, $data);
        $this->listing->refresh($product->id);

        return $this->show($request, $product->refresh());
    }

    public function transition(Request $request, Product $product, string $action): JsonResponse
    {
        if (in_array($action, ['publish', 'unpublish'], true) && ! $request->user()?->can(Permissions::CATALOG_PUBLISH)) {
            throw new ApiException('FORBIDDEN', 403);
        }
        $to = match ($action) {
            'submit' => ProductStatus::PENDING_REVIEW,
            'publish' => ProductStatus::PUBLISHED,
            'unpublish' => ProductStatus::UNPUBLISHED,
            'archive' => ProductStatus::ARCHIVED,
            'return-to-draft' => ProductStatus::DRAFT,
        };
        $this->publisher->transition($product, $to);

        return $this->show($request, $product->refresh());
    }

    public function availability(AvailabilityRequest $request, Product $product): JsonResponse
    {
        /** @var list<array{country: string, is_available: bool, min_days?: int|null, max_days?: int|null, restriction_reason?: string|null}> $rows */
        $rows = $request->validated('countries');

        DB::transaction(function () use ($rows, $product): void {
            foreach ($rows as $row) {
                $country = $this->countries->find($row['country'])
                    ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422, ['country' => strtoupper($row['country'])]);
                DB::table('product_country_availability')->upsert([[
                    'product_id' => $product->id,
                    'country_id' => $country->id,
                    'is_available' => $row['is_available'],
                    'estimated_delivery_min_days' => $row['min_days'] ?? null,
                    'estimated_delivery_max_days' => $row['max_days'] ?? null,
                    'restriction_reason' => $row['restriction_reason'] ?? null,
                    'updated_at' => now(),
                ]], ['product_id', 'country_id'], ['is_available', 'estimated_delivery_min_days', 'estimated_delivery_max_days', 'restriction_reason', 'updated_at']);
            }
        });
        $this->audit->record('product.availability_updated', 'product', $product->id, null, ['countries' => $rows]);
        $this->listing->refresh($product->id);

        return $this->show($request, $product);
    }

    private function shareCountryCodes(Request $request): void
    {
        $map = [];
        foreach ($this->countries->active() as $c) {
            $map[$c->id] = $c->code;
        }
        $request->attributes->set('country_codes_by_id', $map);
    }
}
