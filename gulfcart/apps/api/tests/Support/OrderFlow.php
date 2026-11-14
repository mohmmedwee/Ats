<?php

namespace Tests\Support;

use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Payments\Application\Gateways\SandboxGateway;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Drives the real API from cart to paid order. Uses CatalogFixtures. */
trait OrderFlow
{
    /** @return array<string, string> */
    protected function customerAuth(?User $user = null): array
    {
        $user ??= $this->customer();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    /**
     * @param  list<array{0: ProductVariant, 1: int}>  $lines
     * @param  array<string, string>  $auth
     */
    protected function placeOrder(array $lines, array $auth, string $country = 'AE'): Order
    {
        $h = $auth + ['X-Country' => $country];
        foreach ($lines as [$variant, $qty]) {
            $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => $qty], $h)->assertCreated();
        }
        $address = $country === 'AE'
            ? ['country' => 'AE', 'full_name' => 'Sara Ahmed', 'phone' => '0501234567', 'address_line_1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'DXB']
            : ['country' => 'SA', 'full_name' => 'Ali', 'phone' => '0551234567', 'address_line_1' => 'Street 1', 'city' => 'Riyadh', 'state' => 'RIY'];
        $addressId = $this->postJson('/api/v1/addresses', $address, $h)->assertCreated()->json('data.id');
        $sessionId = $this->postJson('/api/v1/checkout', ['address_id' => $addressId, 'shipping_method' => 'standard'], $h)->assertCreated()->json('data.id');
        $orderId = $this->postJson('/api/v1/orders', ['checkout_session_id' => $sessionId], $h + ['Idempotency-Key' => 'order-'.Str::random(20)])
            ->assertCreated()->assertJsonPath('data.status', 'pending_payment')->json('data.id');

        return Order::query()->findOrFail($orderId);
    }

    /** @param array<string, string> $auth */
    protected function createIntent(Order $order, array $auth): Payment
    {
        $id = $this->postJson('/api/v1/payments/intent', ['order_id' => $order->id], $auth + ['Idempotency-Key' => 'pay-'.Str::random(20)])
            ->assertCreated()->json('data.id');

        return Payment::query()->findOrFail($id);
    }

    protected function webhook(array $payload, ?int $timestamp = null): TestResponse
    {
        /** @var SandboxGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $signed = $gateway->sign($payload, $timestamp);

        return $this->call('POST', '/api/v1/webhooks/payments/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => $signed['signature'],
        ], $signed['body']);
    }

    protected function capture(Payment $payment, ?string $amount = null, string $eventId = ''): TestResponse
    {
        return $this->webhook([
            'id' => $eventId !== '' ? $eventId : 'evt_'.Str::random(16), 'type' => 'payment.captured',
            'payment_reference' => $payment->provider_reference, 'amount' => $amount ?? $payment->amount,
            'currency' => $payment->currency, 'method' => 'card',
        ]);
    }

    /** @param array<string, string> $auth */
    protected function payOrder(Order $order, array $auth): Payment
    {
        $payment = $this->createIntent($order, $auth);
        $this->capture($payment)->assertOk()->assertJsonPath('data.result', 'processed');

        return $payment->refresh();
    }

    /** @return Collection<int, SupplierOrder> */
    protected function supplierOrders(Order $order): Collection
    {
        return SupplierOrder::query()->with('task')->where('order_id', $order->id)->orderBy('created_at')->get();
    }

    protected function setMock(Supplier $supplier, array $mock): void
    {
        $supplier->refresh()->update(['metadata' => array_merge($supplier->metadata, ['mock' => $mock])]);
    }
}
