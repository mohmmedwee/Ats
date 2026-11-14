<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Identity\Domain\Permissions;
use App\Modules\Payments\Application\RefundService;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminRefundController
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $q = Refund::query()->latest();
        if (is_string($s = $request->query('status'))) {
            $q->where('status', $s);
        }
        if (is_string($o = $request->query('order_id'))) {
            $q->where('order_id', $o);
        }
        $page = $q->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paged($page, $page->getCollection()->map(fn ($r) => $this->present($r))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reason_code' => ['required', 'in:customer_request,procurement_failed,damaged,not_delivered,goodwill,cancellation,price_adjustment'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array'],
            'items.*' => ['integer', 'min:1'],
        ]);
        $user = $request->user();
        $created = $this->refunds->request(
            $data['order_id'], (string) $data['amount'], $data['reason_code'], $data['reason'] ?? null,
            (string) $user->getAuthIdentifier(), $user->can(Permissions::REFUNDS_APPROVE), $data['items'] ?? [],
        );

        return ApiResponse::success(array_map(fn ($r) => $this->present($r), $created), 201);
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        return ApiResponse::success($this->present($this->refunds->approve($refund->id, (string) $request->user()->getAuthIdentifier())));
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        return ApiResponse::success($this->present($this->refunds->reject($refund->id, (string) $request->user()->getAuthIdentifier(), $reason)));
    }

    /** @return array<string, mixed> */
    private function present(Refund $r): array
    {
        return $r->only(['id', 'order_id', 'payment_id', 'return_id', 'amount', 'currency', 'reason_code', 'reason', 'item_quantities', 'status', 'requested_by', 'approved_by', 'failure_reason'])
            + ['created_at' => $r->created_at?->toIso8601String()];
    }
}
