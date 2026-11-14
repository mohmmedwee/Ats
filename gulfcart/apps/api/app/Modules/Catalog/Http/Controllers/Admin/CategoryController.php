<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Http\Requests\CategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CategoryController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()->orderBy('sort_order')->orderBy('slug')->get();

        return ApiResponse::success(CategoryResource::collection($categories)->resolve($request));
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = Category::query()->create($request->validated());
        $this->audit->record('category.created', 'category', $category->id, null, $request->validated());

        return ApiResponse::success((new CategoryResource($category))->resolve($request), 201);
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['parent_id']) && $this->wouldCreateCycle($category, $data['parent_id'])) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['parent_id' => ['A category cannot be its own ancestor.']]]);
        }
        $old = $category->only(array_keys($data));
        $category->update($data);
        $this->audit->record('category.updated', 'category', $category->id, $old, $data);

        return ApiResponse::success((new CategoryResource($category))->resolve($request));
    }

    public function destroy(Category $category): Response
    {
        if (Product::query()->where('category_id', $category->id)->exists() || $category->children()->exists()) {
            throw new ApiException('CONFLICT', 409, [], 'Category has products or subcategories.');
        }
        $category->delete();
        $this->audit->record('category.deleted', 'category', $category->id);

        return response()->noContent();
    }

    private function wouldCreateCycle(Category $category, string $parentId): bool
    {
        $guard = 0;
        for ($id = $parentId; $id !== null && $guard++ < 50; $id = Category::query()->whereKey($id)->value('parent_id')) {
            if ($id === $category->id) {
                return true;
            }
        }

        return false;
    }
}
