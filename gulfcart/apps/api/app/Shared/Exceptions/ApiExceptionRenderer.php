<?php

namespace App\Shared\Exceptions;

use App\Shared\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionDenied;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Maps every exception on /api/* to the standard error envelope and error codes. */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->localizedMessage(), $e->status, $e->details),
            $e instanceof ValidationException => ApiResponse::error(
                'VALIDATION_FAILED', self::t('validation_failed'), 422, ['fields' => $e->errors()],
            ),
            $e instanceof AuthenticationException => ApiResponse::error('UNAUTHENTICATED', self::t('unauthenticated'), 401),
            $e instanceof AuthorizationException,
            $e instanceof PermissionDenied,
            $e instanceof AccessDeniedHttpException => ApiResponse::error('FORBIDDEN', self::t('forbidden'), 403),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error('NOT_FOUND', self::t('not_found'), 404),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error('METHOD_NOT_ALLOWED', self::t('method_not_allowed'), 405),
            $e instanceof InvalidSignatureException => ApiResponse::error('INVALID_SIGNATURE', self::t('invalid_signature'), 403),
            $e instanceof TokenMismatchException => ApiResponse::error('CSRF_TOKEN_MISMATCH', self::t('csrf_token_mismatch'), 419),
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'RATE_LIMITED', self::t('rate_limited'), 429, [], self::stringHeaders($e->getHeaders()),
            ),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getStatusCode() >= 500 ? 'SERVICE_UNAVAILABLE' : 'BAD_REQUEST',
                self::t($e->getStatusCode() >= 500 ? 'service_unavailable' : 'bad_request'),
                $e->getStatusCode(),
            ),
            default => self::internal($e),
        };
    }

    private static function internal(Throwable $e): JsonResponse
    {
        $details = config('app.debug') ? ['exception' => $e::class, 'message' => $e->getMessage()] : [];

        return ApiResponse::error('INTERNAL_ERROR', self::t('internal_error'), 500, $details);
    }

    private static function t(string $key): string
    {
        return (string) __('errors.'.$key);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        return array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $headers);
    }
}
