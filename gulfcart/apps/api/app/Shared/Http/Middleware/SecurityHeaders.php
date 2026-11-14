<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Security headers for API responses (docs/SECURITY_SPEC.md §1). */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Keep a stricter-or-specific policy set by the controller (e.g. printable invoice HTML).
        if (! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $h->set('Cache-Control', $h->get('Cache-Control') && str_contains((string) $h->get('Cache-Control'), 'public') ? (string) $h->get('Cache-Control') : 'no-store, private');
        if (app()->environment('production')) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $h->remove('X-Powered-By');

        return $response;
    }
}
