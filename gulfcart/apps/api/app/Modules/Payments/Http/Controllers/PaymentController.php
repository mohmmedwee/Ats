<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Countries\Domain\CountryData;
use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Payments\Application\GatewayRegistry;
use App\Modules\Payments\Application\Gateways\SandboxGateway;
use App\Modules\Payments\Application\PaymentService;
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
        $data = $request->validate(['order_id' => ['required', 'uuid'], 'provider' => ['nullable', 'string', 'max:30']]);
        $payment = $this->payments->createIntent($this->userId($request), $data['order_id'], (string) $request->headers->get(EnsureIdempotency::HEADER), $data['provider'] ?? null);

        return ApiResponse::success($this->present($payment), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->owned($request, $id)));
    }

    /** Payment methods the customer can choose at checkout, default first. */
    public function methods(Request $request, GatewayRegistry $gateways): JsonResponse
    {
        /** @var CountryData $country */
        $country = $request->attributes->get('country');
        $codCountries = array_filter(array_map('trim', explode(',', (string) config('gulfcart.cod.countries', ''))));
        $methods = [];
        foreach ($gateways->enabled() as $name) {
            $row = ['provider' => $name, 'currency_note' => $name === 'paypal' ? 'USD' : null];
            if ($name === 'cod') {
                $check = $this->payments->codEligibility($this->userId($request));
                if (! in_array($country->code, $codCountries, true)) {
                    continue;
                }
                $max = config("gulfcart.cod.max_amount.{$country->code}");
                $row += [
                    'fee' => ['amount' => number_format((float) config("gulfcart.cod.fee.{$country->code}", 0), 2, '.', ''), 'currency' => $country->currency],
                    'max_amount' => $max !== null && $max !== '' ? ['amount' => number_format((float) $max, 2, '.', ''), 'currency' => $country->currency] : null,
                    'available' => $check['eligible'], 'reason' => $check['reason'],
                    'requires_code' => (bool) config('gulfcart.cod.require_otp', true),
                ];
            }
            $methods[] = $row;
        }

        return ApiResponse::success($methods);
    }

    /** Cash on delivery: the customer enters the SMS code to confirm the order. */
    public function confirmCashOnDelivery(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return ApiResponse::success($this->present($this->payments->confirmCashOnDelivery($this->owned($request, $id), $data['code'])));
    }

    public function resendCashOnDeliveryCode(Request $request, string $id): JsonResponse
    {
        $this->payments->resendCashOnDeliveryCode($this->owned($request, $id));

        return ApiResponse::success(['sent' => true]);
    }

    /** Called by the return page: finishes payments the merchant must capture (PayPal). */
    public function confirm(Request $request, string $id): JsonResponse
    {
        $payment = $this->owned($request, $id);

        return ApiResponse::success($this->present($this->payments->confirm($payment->id)));
    }

    /** Provider webhook. Unauthenticated; trust comes only from the signature. */
    public function webhook(Request $request, string $provider, GatewayRegistry $gateways): JsonResponse
    {
        if (! in_array($provider, $gateways->enabled(), true)) {
            throw new ApiException('NOT_FOUND', 404);
        }
        try {
            $result = $this->payments->handleWebhook($request, $provider);
        } catch (InvalidWebhookSignature) {
            throw new ApiException('WEBHOOK_SIGNATURE_INVALID', 401);
        }

        return ApiResponse::success(['result' => $result]);
    }

    /**
     * Sandbox only: simulates the customer completing (or failing) payment on the provider page,
     * which sends a signed webhook exactly like a real provider would.
     */
    public function sandboxComplete(Request $request, string $id, GatewayRegistry $gateways): JsonResponse
    {
        $gateway = in_array('sandbox', $gateways->enabled(), true) ? $gateways->get('sandbox') : null;
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
        $this->payments->handleWebhook($webhook, 'sandbox');

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
