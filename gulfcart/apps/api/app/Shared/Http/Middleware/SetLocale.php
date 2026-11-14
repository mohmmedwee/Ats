<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sets the application locale from Accept-Language (supported: config gulfcart.locales). */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('gulfcart.locales', ['en']);
        $locale = $request->getPreferredLanguage($supported) ?? config('gulfcart.default_locale', 'en');

        app()->setLocale(substr((string) $locale, 0, 2));

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
