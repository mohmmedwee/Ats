<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Payments\Application\PaymentService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff record what happened at the door for cash-on-delivery orders. */
final class AdminCashOnDeliveryController
{
    public function __construct(private readonly PaymentService $payments, private readonly AuditLogger $audit) {}

    public function collected(Request $request, string $orderId): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $payment = $this->payments->recordCashCollected($orderId, $data['note'] ?? null);
        $this->audit->record('cod.collected', 'order', $orderId, null, ['payment_id' => $payment->id, 'amount' => (string) $payment->amount, 'note' => $data['note'] ?? null]);

        return ApiResponse::success(['order_id' => $orderId, 'payment_status' => $payment->status]);
    }

    public function refused(Request $request, string $orderId): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $payment = $this->payments->recordCashRefused($orderId, $data['note'] ?? null);
        $this->audit->record('cod.refused', 'order', $orderId, null, ['payment_id' => $payment->id, 'note' => $data['note'] ?? null]);

        return ApiResponse::success(['order_id' => $orderId, 'payment_status' => $payment->status]);
    }
}
