<?php

namespace App\Shared\Http;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * Cursor-paginated listing: meta.pagination = { next_cursor, prev_cursor, limit }.
     *
     * @param  CursorPaginator<int, mixed>  $paginator
     * @param  array<int, mixed>  $items
     */
    public static function cursor(CursorPaginator $paginator, array $items): JsonResponse
    {
        return self::success($items, 200, ['pagination' => [
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
            'limit' => $paginator->perPage(),
        ]]);
    }

    /**
     * Page-based listing for admin tables.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  array<int, mixed>  $items
     */
    public static function paged(LengthAwarePaginator $paginator, array $items): JsonResponse
    {
        return self::success($items, 200, ['pagination' => [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]]);
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
