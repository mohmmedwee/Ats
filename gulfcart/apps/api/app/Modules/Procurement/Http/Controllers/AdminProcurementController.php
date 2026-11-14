<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Application\ProcurementActions;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Procurement dashboard and exception actions (procurement.view / procurement.act). */
final class AdminProcurementController
{
    public function __construct(private readonly ProcurementActions $actions) {}

    public function index(Request $request): JsonResponse
    {
        $q = ProcurementTask::query()->with('supplierOrder')->orderByRaw("CASE status WHEN 'exception' THEN 0 WHEN 'manual' THEN 1 WHEN 'reconciling' THEN 2 WHEN 'waiting_retry' THEN 3 ELSE 9 END")->latest();
        if (is_string($s = $request->query('status'))) {
            $q->whereIn('status', explode(',', $s));
        }
        if (is_string($o = $request->query('order_id')) && Str::isUuid($o)) {
            $q->whereHas('supplierOrder', fn ($so) => $so->where('order_id', $o));
        }
        $page = $q->paginate(min(100, $request->integer('per_page', 25)));

        $counts = ProcurementTask::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success(
            $page->getCollection()->map(fn ($t) => $this->present($t, false))->all(),
            200,
            ['pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()], 'counts' => $counts],
        );
    }

    public function show(ProcurementTask $task): JsonResponse
    {
        return ApiResponse::success($this->present($task->load(['supplierOrder.items', 'attemptLog']), true));
    }

    public function retry(Request $request, ProcurementTask $task): JsonResponse
    {
        $data = $request->validate(['new_idempotency_key' => ['sometimes', 'boolean']]);
        $this->actions->retry($task, $this->actor($request), (bool) ($data['new_idempotency_key'] ?? false));

        return $this->show($task->refresh());
    }

    public function approve(Request $request, ProcurementTask $task): JsonResponse
    {
        $data = $request->validate([
            'external_order_id' => ['required', 'string', 'max:255'],
            'actual_cost' => ['required', 'numeric', 'min:0'],
        ]);
        $this->actions->approveManual($task, $this->actor($request), $data['external_order_id'], (string) $data['actual_cost']);

        return $this->show($task->refresh());
    }

    public function switchSource(Request $request, ProcurementTask $task): JsonResponse
    {
        $data = $request->validate(['supplier_product_id' => ['required', 'uuid']]);
        $newTaskId = $this->actions->switchSource($task, $this->actor($request), $data['supplier_product_id']);

        return $this->show(ProcurementTask::query()->findOrFail($newTaskId));
    }

    public function cancel(Request $request, ProcurementTask $task): JsonResponse
    {
        $data = $request->validate(['refund' => ['required', 'boolean']]);
        $this->actions->cancel($task, $this->actor($request), (bool) $data['refund']);

        return $this->show($task->refresh());
    }

    /** @return array<string, mixed> */
    private function present(ProcurementTask $t, bool $detailed): array
    {
        $so = $t->supplierOrder;
        $delta = $so->actual_cost !== null ? Decimal::sub((string) $so->actual_cost, (string) $so->expected_cost) : null;

        $base = [
            'id' => $t->id, 'status' => $t->status, 'attempts' => $t->attempts,
            'next_attempt_at' => $t->next_attempt_at?->toIso8601String(), 'last_error' => $t->last_error,
            'age_minutes' => (int) $t->created_at->diffInMinutes(now()),
            'supplier_order' => [
                'id' => $so->id, 'reference' => $so->reference, 'order_id' => $so->order_id, 'supplier_id' => $so->supplier_id,
                'status' => $so->status, 'procurement_mode' => $so->procurement_mode, 'external_order_id' => $so->external_order_id,
                'expected_cost' => $so->expected_cost, 'actual_cost' => $so->actual_cost, 'cost_delta' => $delta, 'currency' => $so->cost_currency,
                'exception_reason' => $so->exception_reason, 'exception_details' => $so->exception_details,
                'idempotency_key' => $so->idempotency_key,
            ],
        ];

        return $detailed ? $base + [
            'items' => $so->items->map(fn ($i) => $i->only(['order_item_id', 'supplier_product_id', 'external_product_id', 'external_variant_id', 'purchase_quantity', 'purchase_cost', 'shipping_cost', 'currency']))->all(),
            'attempt_log' => $t->attemptLog->map(fn ($a) => $a->only(['attempt_number', 'action', 'outcome', 'error_class', 'idempotency_key', 'response_payload', 'duration_ms', 'performed_by']) + ['at' => $a->created_at?->toIso8601String()])->all(),
        ] : $base;
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
