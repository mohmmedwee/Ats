<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Procurement\Infrastructure\Models\ProcurementAttempt;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Modules\Suppliers\Domain\Connector\PurchaseRequest;

/** Append-only record of every supplier interaction (addresses and secrets redacted). */
final class AttemptLog
{
    private const REDACT = ['phone', 'full_name', 'address_line_1', 'address_line_2', 'api_key', 'token', 'secret', 'password'];

    /** @param array<string, mixed> $response */
    public function record(ProcurementTask $task, string $action, string $outcome, ?string $errorClass, ?string $key, ?PurchaseRequest $request, array $response, float $started, ?string $actorId = null): void
    {
        ProcurementAttempt::query()->create([
            'procurement_task_id' => $task->id,
            'attempt_number' => $task->attempts,
            'action' => $action,
            'outcome' => $outcome,
            'error_class' => $errorClass,
            'idempotency_key' => $key,
            'request_payload' => $request === null ? null : $this->redact([
                'reference' => $request->reference,
                'lines' => array_map(fn ($l) => $l->toArray(), $request->lines),
                'shipping_address' => $request->shippingAddress,
            ]),
            'response_payload' => $this->redact($response),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'performed_by' => $actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->redact($v);
            } elseif (in_array((string) $k, self::REDACT, true)) {
                $data[$k] = '[redacted]';
            }
        }

        return $data;
    }
}
