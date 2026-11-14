<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Payments\Application\Gateways\SandboxGateway;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Domain\InvalidWebhookSignature;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Idempotency\EnsureIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController
{
    public function __construct(private readonly PaymentService $payments, private readonly OrderBook $orders) {}

    public function intent(Request $request): JsonResponse
    {
        $data = $request->validate(['order_id' => ['required', 'uuid']]);
        $payment = $this->payments->createIntent($this->userId($request), $data['order_id'], (string) $request->headers->get(EnsureIdempotency::HEADER));

        return ApiResponse::success($this->present($payment), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->owned($request, $id)));
    }

    /** Provider webhook. Unauthenticated; trust comes only from the signature. */
    public function webhook(Request $request, string $provider, PaymentGateway $gateway): JsonResponse
    {
        if ($provider !== $gateway->name()) {
            throw new ApiException('NOT_FOUND', 404);
        }
        try {
            $result = $this->payments->handleWebhook($request);
        } catch (InvalidWebhookSignature) {
            throw new ApiException('WEBHOOK_SIGNATURE_INVALID', 401);
        }

        return ApiResponse::success(['result' => $result]);
    }

    /**
     * Sandbox only: simulates the customer completing (or failing) payment on the provider page,
     * which sends a signed webhook exactly like a real provider would.
     */
    public function sandboxComplete(Request $request, string $id, PaymentGateway $gateway): JsonResponse
    {
        if (! $gateway instanceof SandboxGateway || app()->environment('production')) {
            throw new ApiException('NOT_FOUND', 404);
        }
        $outcome = $request->validate(['outcome' => ['required', 'in:success,failure']])['outcome'];
        $payment = $this->owned($request, $id);

        $signed = $gateway->sign([
            'type' => $outcome === 'success' ? 'payment.captured' : 'payment.failed',
            'payment_reference' => $payment->provider_reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'method' => 'card',
            'failure_code' => $outcome === 'success' ? null : 'card_declined',
        ]);
        $webhook = Request::create('/api/v1/webhooks/payments/sandbox', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => $signed['signature'],
        ], $signed['body']);
        $this->payments->handleWebhook($webhook);

        return ApiResponse::success($this->present($payment->refresh()));
    }

    /** @return array<string, mixed> */
    private function present(Payment $p): array
    {
        $order = $this->orders->get($p->order_id);

        return [
            'id' => $p->id, 'order_id' => $p->order_id, 'order_number' => $order?->orderNumber, 'order_status' => $order?->status,
            'provider' => $p->provider, 'status' => $p->status,
            'amount' => ['amount' => (string) $p->amount, 'currency' => $p->currency],
            'redirect_url' => in_array($p->status, ['created', 'requires_action'], true) ? $p->redirect_url : null,
            'failure_code' => $p->failure_code, 'paid_at' => $p->paid_at?->toIso8601String(),
        ];
    }

    private function owned(Request $request, string $id): Payment
    {
        $payment = Payment::query()->find($id);
        if ($payment === null || $this->orders->get($payment->order_id)?->userId !== $this->userId($request)) {
            throw new ApiException('NOT_FOUND', 404);
        }

        return $payment;
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
