<?php

namespace App\Modules\Returns\Http\Controllers;

use App\Modules\Orders\Contracts\FulfillmentView;
use App\Modules\Returns\Application\ReturnService;
use App\Modules\Returns\Infrastructure\Models\ReturnRequest;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ReturnController
{
    private const REASONS = ['damaged', 'wrong_item', 'not_as_described', 'size_fit', 'changed_mind', 'other'];

    public function __construct(private readonly ReturnService $returns, private readonly FulfillmentView $fulfillment) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.reason_code' => ['required', 'in:'.implode(',', self::REASONS)],
            'reason_code' => ['sometimes', 'in:'.implode(',', self::REASONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $deliveredAt = collect($this->fulfillment->trackingFor($id))->pluck('delivered_at')->filter()->max();

        $return = $this->returns->request(
            (string) $request->user()->getAuthIdentifier(), $id, $data['items'],
            $data['reason_code'] ?? $data['items'][0]['reason_code'], $data['note'] ?? null,
            $deliveredAt ? Carbon::parse($deliveredAt) : null,
        );

        return ApiResponse::success($this->present($return->load('items')), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = ReturnRequest::query()->with('items')->where('user_id', $request->user()->getAuthIdentifier())->latest()->limit(100)->get();

        return ApiResponse::success($rows->map(fn ($r) => $this->present($r))->all());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $return = ReturnRequest::query()->with('items')->where('user_id', $request->user()->getAuthIdentifier())->whereKey($id)->first()
            ?? throw new ApiException('NOT_FOUND', 404);

        return ApiResponse::success($this->present($return));
    }

    /** @return array<string, mixed> */
    public static function present(ReturnRequest $r): array
    {
        return [
            'id' => $r->id, 'rma_number' => $r->rma_number, 'order_id' => $r->order_id, 'status' => $r->status,
            'reason_code' => $r->reason_code, 'customer_note' => $r->customer_note,
            'refund' => $r->refund_amount !== null ? ['amount' => (string) $r->refund_amount, 'currency' => $r->currency] : null,
            'items' => $r->items->map(fn ($i) => ['order_item_id' => $i->order_item_id, 'quantity' => $i->quantity, 'reason_code' => $i->reason_code, 'condition' => $i->condition])->all(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
