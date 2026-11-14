<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Checkout\Contracts\CheckoutSessions;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Exceptions\ApiException;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/** Creates an order (pending_payment) from a locked checkout session, in one transaction. */
final class OrderPlacement
{
    public function __construct(
        private readonly CheckoutSessions $sessions,
        private readonly CartReader $carts,
        private readonly AuditLogger $audit,
    ) {}

    public function place(string $userId, string $sessionId, string $locale): Order
    {
        return DB::transaction(function () use ($userId, $sessionId, $locale): Order {
            $session = $this->sessions->lockForOrder($sessionId, $userId) ?? throw new ApiException('NOT_FOUND', 404);

            $existing = Order::query()->where('checkout_session_id', $session->id)->first();
            if ($existing !== null) {
                return $existing; // natural idempotency: one order per checkout session
            }
            if (! $session->usable) {
                throw new ApiException('QUOTE_EXPIRED', 410);
            }

            $q = $session->quote;
            $number = 'GC-'.DB::selectOne("select nextval('order_number_seq') as n")->n;
            $order = Order::query()->create([
                'order_number' => $number,
                'user_id' => $userId,
                'checkout_session_id' => $session->id,
                'country_id' => $session->countryId,
                'address_id' => $session->addressId,
                'shipping_address_snapshot' => $q['shipping_address'],
                'shipping_method_snapshot' => $q['shipping_method'],
                'currency' => $session->currency,
                'locale' => $locale,
                'status' => OrderStatus::PENDING_PAYMENT,
                'subtotal' => $q['totals']['subtotal'],
                'shipping_total' => $q['totals']['shipping'],
                'tax_total' => $q['totals']['tax'],
                'customs_total' => $q['totals']['customs'],
                'discount_total' => $q['totals']['discount'],
                'grand_total' => $q['totals']['total'],
                'placed_at' => now(),
                'payment_due_at' => now()->addMinutes((int) config('gulfcart.orders.payment_window_minutes', 60)),
            ]);

            foreach ($q['lines'] as $line) {
                $order->items()->create([
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'supplier_product_id' => $line['supplier_product_id'],
                    'supplier_id' => $line['supplier_id'],
                    'product_snapshot' => $line['snapshot'],
                    'quantity' => $line['quantity'],
                    'unit_gross_price' => $line['unit_gross'],
                    'unit_net_price' => $line['unit_net'],
                    'unit_tax' => $line['unit_vat'],
                    'vat_rate' => $line['vat_rate'],
                    'line_total' => $line['line_total'],
                    'unit_supplier_cost' => $line['unit_cost'],
                    'unit_supplier_shipping' => $line['unit_shipping_cost'],
                    'supplier_currency' => $line['cost_currency'],
                    'exchange_rate_id' => $line['exchange_rate_id'],
                    'exchange_rate' => $line['exchange_rate'],
                    'pricing_snapshot' => $line['pricing'],
                    'shipment_group' => $line['shipment'],
                ]);
            }

            $this->sessions->markCompleted($session->id);
            $this->carts->markConverted($session->cartId);
            Outbox::record('order', $order->id, 'order.created', ['order_id' => $order->id, 'order_number' => $number]);
            $this->audit->record('order.placed', 'order', $order->id, null, ['order_number' => $number, 'total' => $order->grand_total], $userId);

            return $order;
        });
    }
}
