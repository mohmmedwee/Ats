<?php

namespace App\Modules\Countries\Http\Middleware;

use App\Modules\Countries\Contracts\CountryDirectory;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request's country from X-Country or ?country= (docs/COUNTRY_CONFIGURATION.md §3)
 * and stores it as the `country` request attribute (CountryData).
 */
final class ResolveCountry
{
    public function __construct(private readonly CountryDirectory $countries) {}

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->headers->get('X-Country') ?? $request->query('country');

        if (is_string($code) && $code !== '') {
            $country = $this->countries->find($code)
                ?? throw new ApiException('COUNTRY_NOT_SUPPORTED', 422, ['country' => strtoupper(substr($code, 0, 2))]);
        } else {
            $country = $this->countries->default();
        }

        $request->attributes->set('country', $country);

        $response = $next($request);
        $response->headers->set('X-Country', $country->code);

        return $response;
    }
}
