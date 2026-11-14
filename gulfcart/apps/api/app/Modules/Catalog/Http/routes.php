<?php

use App\Modules\Catalog\Http\Controllers\Admin\CategoryController;
use App\Modules\Catalog\Http\Controllers\Admin\ImageController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductController;
use App\Modules\Catalog\Http\Controllers\Admin\VariantController;
use App\Modules\Catalog\Http\Controllers\StorefrontCatalogController;
use App\Modules\Identity\Domain\Permissions;
use Illuminate\Support\Facades\Route;

// Storefront
Route::get('categories', [StorefrontCatalogController::class, 'categories']);
Route::get('categories/{slug}/products', [StorefrontCatalogController::class, 'byCategory']);
Route::get('products', [StorefrontCatalogController::class, 'index']);
Route::get('products/search', [StorefrontCatalogController::class, 'search']);
Route::get('products/{id}/variants', [StorefrontCatalogController::class, 'variants'])->whereUuid('id');
Route::get('products/{id}/availability', [StorefrontCatalogController::class, 'availability'])->whereUuid('id');
Route::get('products/{slug}', [StorefrontCatalogController::class, 'show']);

// Admin
Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::CATALOG_MANAGE])->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::patch('categories/{category}', [CategoryController::class, 'update']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::patch('products/{product}', [ProductController::class, 'update']);
    Route::put('products/{product}/availability', [ProductController::class, 'availability']);
    // publish/unpublish additionally require catalog.publish (checked in the controller).
    Route::post('products/{product}/{action}', [ProductController::class, 'transition'])
        ->whereIn('action', ['submit', 'return-to-draft', 'archive', 'publish', 'unpublish']);

    Route::post('products/{product}/variants', [VariantController::class, 'store']);
    Route::patch('variants/{variant}', [VariantController::class, 'update']);

    Route::post('products/{product}/images', [ImageController::class, 'store']);
    Route::patch('images/{image}', [ImageController::class, 'rights'])->middleware('permission:'.Permissions::CATALOG_PUBLISH);
    Route::delete('images/{image}', [ImageController::class, 'destroy']);
});
