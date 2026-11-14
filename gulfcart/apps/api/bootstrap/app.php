<?php

use App\Modules\Countries\Http\Middleware\ResolveCountry;
use App\Modules\Identity\Http\Middleware\EnsureMfaVerified;
use App\Shared\Exceptions\ApiException;
use App\Shared\Exceptions\ApiExceptionRenderer;
use App\Shared\Http\Middleware\RequestId;
use App\Shared\Http\Middleware\SecurityHeaders;
use App\Shared\Http\Middleware\SetLocale;
use App\Shared\Idempotency\EnsureIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->statefulApi();
        $middleware->api(prepend: [SetLocale::class, SecurityHeaders::class]);
        $middleware->throttleApi('api');
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'country' => ResolveCountry::class,
            'idempotent' => EnsureIdempotency::class,
            'mfa.verified' => EnsureMfaVerified::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? ApiExceptionRenderer::render($e) : null;
        });
        $exceptions->dontReport([ApiException::class]);
    })->create();
