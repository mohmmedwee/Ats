<?php

namespace App\Shared\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HealthController
{
    public function live(Request $request): JsonResponse
    {
        return $this->envelope($request, ['status' => 'ok', 'checks' => (object) []], 200);
    }

    public function ready(Request $request, HealthChecker $checker): JsonResponse
    {
        $result = $checker->ready();

        return $this->envelope($request, $result, $result['status'] === 'ok' ? 200 : 503);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function envelope(Request $request, array $data, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => $status === 200,
            'data' => $data,
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], $status);
    }
}
