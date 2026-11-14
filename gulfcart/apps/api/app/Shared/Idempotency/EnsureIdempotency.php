<?php

namespace App\Shared\Idempotency;

use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotent request handling (docs/SECURITY_SPEC.md §4).
 *
 * The database unique key (user_id, endpoint, key) is the source of truth;
 * a replay of a completed request returns the stored response.
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const LOCK_SECONDS = 60;

    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);
        if (! is_string($key) || $key === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 400);
        }
        if (preg_match('/^[A-Za-z0-9_:.-]{16,255}$/', $key) !== 1) {
            throw new ApiException('BAD_REQUEST', 400, ['header' => self::HEADER]);
        }

        $userId = $request->user()?->getAuthIdentifier();
        $endpoint = $request->method().' '.($request->route()?->uri() ?? $request->path());
        $hash = hash('sha256', (string) json_encode($this->canonical($request->all())));
        $now = Carbon::now();

        $inserted = DB::table('idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'key' => $key,
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'request_hash' => $hash,
            'locked_until' => $now->copy()->addSeconds(self::LOCK_SECONDS),
            'expires_at' => $now->copy()->addHours(self::TTL_HOURS),
            'created_at' => $now,
        ]);

        $scope = DB::table('idempotency_keys')
            ->where('user_id', $userId)
            ->where('endpoint', $endpoint)
            ->where('key', $key);

        if ($inserted === 0) {
            $existing = (clone $scope)->first();
            if ($existing === null) {
                throw new ApiException('CONFLICT', 409);
            }
            if ($existing->request_hash !== $hash) {
                throw new ApiException('IDEMPOTENCY_KEY_REUSED', 409);
            }
            if ($existing->response_status !== null) {
                return new JsonResponse(
                    json_decode((string) $existing->response_body, true),
                    (int) $existing->response_status,
                    ['Idempotent-Replayed' => 'true'],
                );
            }
            // In progress elsewhere; take over only if the lock expired.
            $claimed = (clone $scope)
                ->whereNull('response_status')
                ->where('locked_until', '<', $now)
                ->update(['locked_until' => $now->copy()->addSeconds(self::LOCK_SECONDS)]);
            if ($claimed === 0) {
                throw new ApiException('CONFLICT', 409);
            }
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            (clone $scope)->whereNull('response_status')->delete();
            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            // Let the client retry with the same key.
            (clone $scope)->whereNull('response_status')->delete();

            return $response;
        }

        (clone $scope)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent() ?: 'null',
            'locked_until' => null,
        ]);

        return $response;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->canonical($v), $value);
    }
}
