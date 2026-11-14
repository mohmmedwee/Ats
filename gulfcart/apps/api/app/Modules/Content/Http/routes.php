<?php

use App\Modules\Content\Http\Controllers\PageController;
use App\Modules\Content\Http\Controllers\SiteController;
use App\Modules\Identity\Domain\Permissions;
use Illuminate\Support\Facades\Route;

Route::get('site', [SiteController::class, 'show']);
Route::get('pages', [PageController::class, 'index']);
Route::get('pages/{slug}', [PageController::class, 'show'])->where('slug', '[a-z0-9-]+');

Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::CATALOG_MANAGE])->group(function (): void {
    Route::get('pages', [PageController::class, 'adminIndex']);
    Route::post('pages', [PageController::class, 'store']);
    Route::patch('pages/{page}', [PageController::class, 'update']);
});
