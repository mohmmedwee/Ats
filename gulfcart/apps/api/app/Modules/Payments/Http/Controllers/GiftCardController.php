<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Idempotency\EnsureIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Gift cards: customers check a balance and pay with it; staff issue and switch cards off. */
final class GiftCardController
{
    public function __construct(private readonly PaymentService $payments, private readonly AuditLogger $audit) {}

    public function balance(string $code): JsonResponse
    {
        $card = DB::table('gift_cards')->where('code', strtoupper(trim($code)))->where('is_active', true)->first();
        if ($card === null || ($card->expires_at !== null && now()->greaterThan($card->expires_at))) {
            throw new ApiException('GIFT_CARD_INVALID', 422, ['reason' => 'not_found'], (string) __('errors.gift_card_not_found'));
        }

        return ApiResponse::success(['balance' => ['amount' => (string) $card->balance, 'currency' => $card->currency], 'expires_at' => $card->expires_at]);
    }

    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate(['order_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:24']]);
        $payment = $this->payments->payWithGiftCard((string) $request->user()?->getAuthIdentifier(), $data['order_id'], $data['code'], (string) $request->headers->get(EnsureIdempotency::HEADER));
        $paid = (string) Payment::query()->where('order_id', $data['order_id'])->where('status', 'captured')->sum('amount');

        return ApiResponse::success([
            'payment_id' => $payment->id, 'amount' => ['amount' => (string) $payment->amount, 'currency' => $payment->currency],
            'order_paid_so_far' => ['amount' => number_format((float) $paid, 2, '.', ''), 'currency' => $payment->currency],
        ], 201);
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(DB::table('gift_cards')->orderByDesc('created_at')->limit(500)->get()->map(fn ($c) => [
            'id' => $c->id, 'code' => $c->code, 'currency' => $c->currency, 'initial_amount' => (string) $c->initial_amount,
            'balance' => (string) $c->balance, 'expires_at' => $c->expires_at, 'is_active' => (bool) $c->is_active, 'note' => $c->note, 'created_at' => $c->created_at,
        ])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'currency' => ['required', Rule::in(['AED', 'SAR', 'KWD', 'QAR', 'BHD', 'OMR'])],
            'quantity' => ['sometimes', 'integer', 'between:1,100'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $codes = [];
        DB::transaction(function () use ($data, $request, &$codes) {
            for ($i = 0; $i < ($data['quantity'] ?? 1); $i++) {
                $code = 'GC-'.implode('-', str_split(strtoupper(Str::random(12)), 4));
                $id = (string) Str::uuid7();
                $amount = number_format((float) $data['amount'], 2, '.', '');
                DB::table('gift_cards')->insert([
                    'id' => $id, 'code' => $code, 'currency' => $data['currency'], 'initial_amount' => $amount, 'balance' => $amount,
                    'expires_at' => $data['expires_at'] ?? null, 'is_active' => true, 'note' => $data['note'] ?? null,
                    'issued_by' => $request->user()?->getAuthIdentifier(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('gift_card_transactions')->insert(['id' => (string) Str::uuid7(), 'gift_card_id' => $id, 'type' => 'issue', 'amount' => $amount, 'created_at' => now()]);
                $codes[] = $code;
            }
        });
        $this->audit->record('gift_card.issued', 'gift_card', null, null, ['count' => count($codes), 'amount' => $data['amount'], 'currency' => $data['currency'], 'note' => $data['note'] ?? null]);

        return ApiResponse::success(['codes' => $codes], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        if (DB::table('gift_cards')->where('id', $id)->update(['is_active' => $data['is_active'], 'updated_at' => now()]) === 0) {
            throw new ApiException('NOT_FOUND', 404);
        }
        $this->audit->record('gift_card.updated', 'gift_card', $id, null, $data);

        return ApiResponse::success(['id' => $id, 'is_active' => $data['is_active']]);
    }
}
