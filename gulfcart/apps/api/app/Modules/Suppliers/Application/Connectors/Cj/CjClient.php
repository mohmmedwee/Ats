<?php

namespace App\Modules\Suppliers\Application\Connectors\Cj;

use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Domain\Connector\TransientConnectorException;
use App\Modules\Suppliers\Domain\Connector\UnknownOutcomeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP client for the CJdropshipping API 2.0 (https://developers.cjdropshipping.com).
 *
 * Envelope: {code, result, message, data, requestId}; code 200 is success.
 * Auth: POST authentication/getAccessToken {apiKey} returns a long-lived access token that is
 * cached per API key (CJ rate-limits token requests), sent as the CJ-Access-Token header.
 *
 * Error classification follows the connector contract:
 *  - nothing was processed (connect failure, 429 / rate-limit codes)  -> TransientConnectorException
 *  - a write may have been processed (timeout, 5xx)                   -> UnknownOutcomeException
 *  - CJ refused the request                                           -> CjApiException (permanent)
 */
final class CjClient
{
    private const INVALID_TOKEN_CODES = [1600001, 1600002, 1600031];

    private const RATE_LIMIT_CODES = [1600200, 1600201];

    private const SERVER_CODES = [1600000, 1600301];

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 20,
    ) {
        if ($apiKey === '') {
            throw new ConnectorException('CJdropshipping API key is not configured (CJ_API_KEY).');
        }
    }

    /** @param array<string, scalar|null> $query */
    public function get(string $path, array $query = []): mixed
    {
        return $this->call('GET', $path, array_filter($query, fn ($v) => $v !== null), null, false);
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body, bool $isWrite = true): mixed
    {
        return $this->call('POST', $path, [], $body, $isWrite);
    }

    /** @param array<string, scalar|null> $query */
    public function delete(string $path, array $query): mixed
    {
        return $this->call('DELETE', $path, $query, null, true);
    }

    /** Verifies the API key by requesting a token. */
    public function ping(): void
    {
        $this->token(fresh: false);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function call(string $method, string $path, array $query, ?array $body, bool $isWrite, bool $retriedToken = false): mixed
    {
        $token = $this->token();
        $response = $this->send($method, $path, $query, $body, $isWrite, $token);
        $envelope = $this->envelope($response, $isWrite);
        $code = (int) ($envelope['code'] ?? 0);

        if ($code === 200 || ($code === 0 && ($envelope['result'] ?? false) === true)) {
            return $envelope['data'] ?? null;
        }
        if (in_array($code, self::INVALID_TOKEN_CODES, true) && ! $retriedToken) {
            Cache::forget($this->cacheKey());

            return $this->call($method, $path, $query, $body, $isWrite, true);
        }

        throw $this->error($code, $envelope, $isWrite);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $method, string $path, array $query, ?array $body, bool $isWrite, ?string $token): Response
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $request = Http::acceptJson()->timeout($this->timeoutSeconds)->connectTimeout(min(10, $this->timeoutSeconds));
        if ($token !== null) {
            $request = $request->withHeaders(['CJ-Access-Token' => $token]);
        }

        try {
            return match ($method) {
                'GET' => $request->get($url, $query),
                'DELETE' => $request->delete($url.($query === [] ? '' : '?'.http_build_query($query))),
                default => $request->asJson()->post($url, $body === [] ? (object) [] : $body),
            };
        } catch (ConnectionException $e) {
            // cURL 6/7: DNS or connect failure, nothing reached CJ. Anything else (e.g. 28 timeout) may have.
            $notSent = preg_match('/cURL error (6|7):/', $e->getMessage()) === 1;
            if ($notSent || ! $isWrite) {
                throw new TransientConnectorException("CJdropshipping unreachable: {$e->getMessage()}", previous: $e);
            }

            throw new UnknownOutcomeException("CJdropshipping did not answer after the request was sent: {$e->getMessage()}", previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private function envelope(Response $response, bool $isWrite): array
    {
        $status = $response->status();
        if ($status === 429) {
            throw new TransientConnectorException('CJdropshipping rate limit (HTTP 429).');
        }
        if ($status >= 500) {
            $message = "CJdropshipping server error (HTTP {$status}).";
            throw $isWrite ? new UnknownOutcomeException($message) : new TransientConnectorException($message);
        }
        $json = $response->json();
        if (! is_array($json)) {
            $message = "CJdropshipping returned a non-JSON response (HTTP {$status}).";
            throw $isWrite ? new UnknownOutcomeException($message) : new TransientConnectorException($message);
        }

        return $json;
    }

    /** @param array<string, mixed> $envelope */
    private function error(int $code, array $envelope, bool $isWrite): ConnectorException
    {
        $message = 'CJdropshipping error '.$code.': '.(is_string($envelope['message'] ?? null) ? $envelope['message'] : 'unknown');
        if (in_array($code, self::RATE_LIMIT_CODES, true)) {
            return new TransientConnectorException($message);
        }
        if (in_array($code, self::SERVER_CODES, true)) {
            return $isWrite ? new UnknownOutcomeException($message) : new TransientConnectorException($message);
        }

        return new CjApiException($message, $code);
    }

    private function token(bool $fresh = false): string
    {
        $key = $this->cacheKey();
        if (! $fresh && is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $response = $this->send('POST', 'authentication/getAccessToken', [], ['apiKey' => $this->apiKey], false, null);
        $envelope = $this->envelope($response, false);
        $data = $envelope['data'] ?? null;
        if ((int) ($envelope['code'] ?? 0) !== 200 || ! is_array($data) || ! is_string($data['accessToken'] ?? null)) {
            throw $this->error((int) ($envelope['code'] ?? 0), $envelope, false);
        }

        try {
            $expires = Carbon::parse((string) ($data['accessTokenExpiryDate'] ?? ''));
        } catch (Throwable) {
            $expires = now()->addDays(14);
        }
        // Renew a day early; never cache for less than an hour.
        $ttl = max(3600, (int) now()->diffInSeconds($expires->subDay(), false));
        Cache::put($key, $data['accessToken'], $ttl);

        return $data['accessToken'];
    }

    private function cacheKey(): string
    {
        return 'suppliers.cj.token.'.sha1($this->baseUrl.'|'.$this->apiKey);
    }
}
