<?php

use App\Modules\Countries\Http\Controllers\CountryController;
use App\Shared\Health\HealthController;
use Illuminate\Support\Facades\Route;

/*
| All routes here are prefixed with /api/v1 (bootstrap/app.php).
| Health probes must not depend on country resolution or authentication.
*/

Route::get('health/live', [HealthController::class, 'live'])->withoutMiddleware('throttle:api');
Route::get('health/ready', [HealthController::class, 'ready'])->withoutMiddleware('throttle:api');

Route::middleware('country')->group(function (): void {
    Route::get('countries', [CountryController::class, 'index']);
    Route::get('countries/current', [CountryController::class, 'current']);

    require app_path('Modules/Identity/Http/routes.php');
});
