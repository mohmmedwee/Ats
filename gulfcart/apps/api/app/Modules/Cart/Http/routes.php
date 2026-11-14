<?php

use App\Modules\Cart\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

// Guests and customers. Authentication is optional (resolved via the sanctum guard).
Route::prefix('cart')->group(function (): void {
    Route::get('/', [CartController::class, 'show']);
    Route::post('items', [CartController::class, 'add']);
    Route::patch('items/{item}', [CartController::class, 'update'])->whereUuid('item');
    Route::delete('items/{item}', [CartController::class, 'destroy'])->whereUuid('item');
    Route::post('validate', [CartController::class, 'validateCart']);
});
