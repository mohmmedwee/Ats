<?php

use App\Modules\Countries\Http\Controllers\CountryController;
use App\Modules\Identity\Domain\Permissions;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Shared\Health\HealthController;
use Illuminate\Support\Facades\Route;

/*
| All routes here are prefixed with /api/v1 (bootstrap/app.php).
| Health probes must not depend on country resolution or authentication.
*/

Route::get('health/live', [HealthController::class, 'live'])->withoutMiddleware('throttle:api');
Route::get('health/ready', [HealthController::class, 'ready'])->withoutMiddleware('throttle:api');

// Payment provider webhooks: no auth, no country; trust comes from the signature.
Route::post('webhooks/payments/{provider}', [PaymentController::class, 'webhook'])
    ->withoutMiddleware('throttle:api')->middleware('throttle:120,1');

Route::middleware('country')->group(function (): void {
    Route::get('countries', [CountryController::class, 'index']);
    Route::get('countries/current', [CountryController::class, 'current']);
    Route::get('countries/{code}/address-format', [CountryController::class, 'addressFormat'])->where('code', '[A-Za-z]{2}');

    require app_path('Modules/Identity/Http/routes.php');
    require app_path('Modules/Catalog/Http/routes.php');
    require app_path('Modules/Suppliers/Http/routes.php');
    require app_path('Modules/Tax/Http/routes.php');
    require app_path('Modules/Shipping/Http/routes.php');
    require app_path('Modules/Pricing/Http/routes.php');
    require app_path('Modules/Cart/Http/routes.php');
    require app_path('Modules/Checkout/Http/routes.php');
    require app_path('Modules/Orders/Http/routes.php');
    require app_path('Modules/Payments/Http/routes.php');
    require app_path('Modules/Procurement/Http/routes.php');
    require app_path('Modules/Returns/Http/routes.php');
    require app_path('Modules/Analytics/Http/routes.php');
    require app_path('Modules/Audit/Http/routes.php');

    Route::prefix('admin')->middleware(['auth:sanctum', 'mfa.verified', 'permission:'.Permissions::COUNTRIES_MANAGE])->group(function (): void {
        Route::get('countries', [CountryController::class, 'adminIndex']);
        Route::patch('countries/{code}', [CountryController::class, 'adminUpdate'])->where('code', '[A-Za-z]{2}');
    });
});
