<?php

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Suppliers\Http\Controllers\CjController;
use App\Modules\Suppliers\Http\Controllers\ImportController;
use App\Modules\Suppliers\Http\Controllers\SupplierController;
use App\Modules\Suppliers\Http\Controllers\SupplierProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified'])->group(function (): void {
    Route::middleware('permission:'.Permissions::SUPPLIERS_MANAGE)->group(function (): void {
        Route::get('suppliers', [SupplierController::class, 'index']);
        Route::post('suppliers', [SupplierController::class, 'store']);
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::post('suppliers/{supplier}/authorizations', [SupplierController::class, 'addAuthorization']);
        Route::post('suppliers/{supplier}/status', [SupplierController::class, 'status']);
    });

    Route::middleware('permission:'.Permissions::CATALOG_MANAGE)->group(function (): void {
        Route::get('suppliers/{supplier}/products', [SupplierProductController::class, 'index']);
        Route::post('suppliers/{supplier}/products', [SupplierProductController::class, 'store']);
        Route::patch('supplier-products/{supplierProduct}', [SupplierProductController::class, 'update']);
        Route::put('supplier-products/{supplierProduct}/mappings', [SupplierProductController::class, 'mappings']);
        Route::post('supplier-products/{supplierProduct}/sync', [SupplierProductController::class, 'sync']);
        Route::get('imports', [ImportController::class, 'index']);
        Route::post('imports/products', [ImportController::class, 'store']);
        Route::get('cj/products', [CjController::class, 'search']);
        Route::post('cj/imports', [CjController::class, 'import'])->middleware('throttle:10,1');
        Route::get('imports/{import}', [ImportController::class, 'show']);
    });
});
