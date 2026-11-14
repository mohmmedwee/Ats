<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff view including costs and estimated contribution (orders.view_any). */
final class AdminOrderController
{
    public function index(Request $request): JsonResponse
    {
        $q = Order::query()->with('items')->latest();
        if (is_string($s = $request->query('status')) && in_array($s, OrderStatus::states(), true)) {
            $q->where('status', $s);
        }
        if (is_string($n = $request->query('q')) && $n !== '') {
            $q->where('order_number', 'ILIKE', '%'.$n.'%');
        }
        $page = $q->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paged($page, $page->getCollection()->map(fn ($o) => $this->present($o, false))->all());
    }

    public function show(Order $order): JsonResponse
    {
        return ApiResponse::success($this->present($order->load('items'), true));
    }

    /** @return array<string, mixed> */
    private function present(Order $o, bool $detailed): array
    {
        $cost = '0';
        foreach ($o->items as $i) {
            $unit = Decimal::mul(Decimal::add((string) $i->unit_supplier_cost, (string) $i->unit_supplier_shipping, 4), (string) $i->exchange_rate, 4);
            $cost = Decimal::add($cost, Decimal::mul($unit, (string) $i->quantity, 4), 4);
        }
        $net = Decimal::sub((string) $o->grand_total, (string) $o->tax_total, 2);

        $base = [
            'id' => $o->id, 'order_number' => $o->order_number, 'user_id' => $o->user_id, 'status' => $o->status,
            'refund_state' => $o->refund_state, 'currency' => $o->currency,
            'grand_total' => (string) $o->grand_total, 'paid_total' => (string) $o->paid_total, 'refunded_total' => (string) $o->refunded_total,
            'estimated_landed_cost' => Decimal::round($cost, 2),
            'estimated_contribution_before_fees' => Decimal::sub(Decimal::sub($net, (string) $o->customs_total, 2), Decimal::round($cost, 2), 2),
            'placed_at' => $o->placed_at->toIso8601String(), 'paid_at' => $o->paid_at?->toIso8601String(),
        ];

        return $detailed ? $base + [
            'shipping_address' => $o->shipping_address_snapshot,
            'shipping_method' => $o->shipping_method_snapshot,
            'totals' => $o->only(['subtotal', 'shipping_total', 'tax_total', 'customs_total', 'discount_total', 'grand_total']),
            'items' => $o->items->map(fn ($i) => $i->only([
                'id', 'variant_id', 'supplier_id', 'supplier_product_id', 'quantity', 'unit_gross_price', 'line_total',
                'unit_supplier_cost', 'unit_supplier_shipping', 'supplier_currency', 'exchange_rate', 'shipment_group',
                'fulfillment_status', 'refunded_quantity',
            ]) + ['title' => $i->product_snapshot['title'] ?? null, 'sku' => $i->product_snapshot['sku'] ?? null])->all(),
        ] : $base;
    }
}
