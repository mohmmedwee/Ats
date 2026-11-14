<?php

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Builds the standard GulfCart JSON envelopes.
 *
 * Success: { success: true, data, meta: { request_id } }
 * Error:   { success: false, error: { code, message, details }, meta: { request_id } }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data ?? new \stdClass,
            'meta' => self::meta($meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? new \stdClass : $details,
            ],
            'meta' => self::meta(),
        ], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(array $extra = []): array
    {
        $request = app('request');
        $requestId = $request instanceof Request ? $request->attributes->get('request_id') : null;

        return ['request_id' => $requestId] + $extra;
    }
}
