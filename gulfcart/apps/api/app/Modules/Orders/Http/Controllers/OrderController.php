<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Orders\Application\OrderPlacement;
use App\Modules\Orders\Contracts\CancellationPolicy;
use App\Modules\Orders\Contracts\FulfillmentView;
use App\Modules\Orders\Contracts\OrderRefunder;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\I18n\Translations;
use App\Shared\Outbox\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrderController
{
    public function __construct(
        private readonly OrderPlacement $placement,
        private readonly FulfillmentView $fulfillment,
        private readonly CancellationPolicy $cancellation,
        private readonly OrderRefunder $refunder,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = Order::query()->with('items')->where('user_id', $this->userId($request))
            ->orderByDesc('created_at')->orderBy('id')->cursorPaginate(min(50, $request->integer('limit', 20)));

        return ApiResponse::cursor($page, $page->getCollection()->map(fn (Order $o) => $this->summary($o))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['checkout_session_id' => ['required', 'uuid']]);
        $order = $this->placement->place($this->userId($request), $data['checkout_session_id'], app()->getLocale());

        return ApiResponse::success($this->detail($order->load('items')), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->detail($this->owned($request, $id)));
    }

    public function tracking(Request $request, string $id): JsonResponse
    {
        $order = $this->owned($request, $id);

        return ApiResponse::success(['order_number' => $order->order_number, 'status' => $order->status, 'shipments' => $this->fulfillment->trackingFor($order->id)]);
    }

    /**
     * Unpaid orders are cancelled immediately. Paid orders are cancelled and fully refunded only
     * while no supplier purchase has been made; otherwise the customer is directed to returns.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $order = $this->owned($request, $id);

        DB::transaction(function () use ($order) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED], true)) {
                $order->update(['status' => OrderStatus::CANCELLED]);
            } elseif (in_array($order->status, [OrderStatus::PAID, OrderStatus::PROCESSING], true) && $this->cancellation->cancelBeforePurchase($order->id)) {
                $order->update(['status' => OrderStatus::CANCELLED]);
                $remaining = number_format((float) $order->paid_total - (float) $order->refunded_total, 2, '.', '');
                if ((float) $remaining > 0) {
                    $this->refunder->refund($order->id, $remaining, 'cancellation', null);
                }
            } else {
                throw new ApiException('CANCELLATION_NOT_POSSIBLE', 409);
            }
            Outbox::record('order', $order->id, 'order.cancelled', ['order_id' => $order->id]);
            $this->audit->record('order.cancelled_by_customer', 'order', $order->id);
        });

        return ApiResponse::success($this->detail($this->owned($request, $id)));
    }

    /** @return array<string, mixed> */
    private function summary(Order $o): array
    {
        $first = $o->items->first();

        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'refund_state' => $o->refund_state,
            'total' => ['amount' => (string) $o->grand_total, 'currency' => $o->currency],
            'item_count' => (int) $o->items->sum('quantity'),
            'image' => $first?->product_snapshot['image_url'] ?? null,
            'placed_at' => $o->placed_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> customer view: no supplier, cost or margin data */
    private function detail(Order $o): array
    {
        $money = fn ($v) => ['amount' => (string) $v, 'currency' => $o->currency];
        $tracking = collect($this->fulfillment->trackingFor($o->id))->groupBy('shipment_group');

        return $this->summary($o) + [
            'shipping_address' => $o->shipping_address_snapshot,
            'shipping_method' => ['code' => $o->shipping_method_snapshot['code'] ?? null, 'name' => Translations::pick($o->shipping_method_snapshot['name'] ?? null)],
            'packages' => $o->items->groupBy('shipment_group')->map(fn ($items, $group) => [
                'number' => (int) $group,
                'status' => $this->packageStatus($items->pluck('fulfillment_status')->all()),
                'tracking' => $tracking->get($group, collect())->values()->all(),
                'items' => $items->map(fn ($i) => [
                    'id' => $i->id,
                    'title' => Translations::pick($i->product_snapshot['title'] ?? null),
                    'sku' => $i->product_snapshot['sku'] ?? null,
                    'attributes' => (object) ($i->product_snapshot['attributes'] ?? []),
                    'image' => $i->product_snapshot['image_url'] ?? null,
                    'quantity' => $i->quantity,
                    'refunded_quantity' => $i->refunded_quantity,
                    'unit_price' => $money($i->unit_gross_price),
                    'line_total' => $money($i->line_total),
                    'status' => $i->fulfillment_status,
                ])->values()->all(),
            ])->values()->all(),
            'totals' => [
                'subtotal' => $money($o->subtotal), 'shipping' => $money($o->shipping_total), 'customs' => $money($o->customs_total),
                'discount' => $money($o->discount_total), 'tax' => $money($o->tax_total), 'total' => $money($o->grand_total),
                'paid' => $money($o->paid_total), 'refunded' => $money($o->refunded_total),
            ],
            'prices_include_tax' => true,
            'payment_due_at' => $o->status === OrderStatus::PENDING_PAYMENT ? $o->payment_due_at->toIso8601String() : null,
            'can_cancel' => in_array($o->status, [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED, OrderStatus::PAID, OrderStatus::PROCESSING], true)
                && ! $o->items->contains(fn ($i) => in_array($i->fulfillment_status, ['purchased', 'shipped', 'delivered'], true)),
        ];
    }

    /** @param list<string> $statuses */
    private function packageStatus(array $statuses): string
    {
        $active = array_values(array_filter($statuses, fn ($s) => $s !== 'cancelled'));

        return match (true) {
            $active === [] => 'cancelled',
            ! array_diff($active, ['delivered', 'returned']) => 'delivered',
            ! array_diff($active, ['shipped', 'delivered', 'returned']) => 'shipped',
            in_array('purchased', $active, true) || in_array('shipped', $active, true) => 'preparing',
            default => 'pending',
        };
    }

    private function owned(Request $request, string $id): Order
    {
        return Order::query()->with('items')->where('user_id', $this->userId($request))->whereKey($id)->first()
            ?? throw new ApiException('NOT_FOUND', 404);
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
