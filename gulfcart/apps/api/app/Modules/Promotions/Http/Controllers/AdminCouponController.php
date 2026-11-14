<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Promotions\Application\CouponService;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Coupon management (pricing.manage). */
final class AdminCouponController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $coupons = Coupon::query()->withCount(['redemptions as used' => fn ($q) => $q->where('status', 'active')])
            ->withSum(['redemptions as discount_given' => fn ($q) => $q->where('status', 'active')], 'amount')
            ->latest()->get();

        return ApiResponse::success($coupons->map(fn (Coupon $c) => $this->present($c))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $coupon = Coupon::query()->create($data);
        $this->audit->record('coupon.created', 'coupon', $coupon->id, null, $data);

        return ApiResponse::success($this->present($coupon), 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $this->validated($request, $coupon);
        $old = $coupon->only(array_keys($data));
        $coupon->update($data);
        $this->audit->record('coupon.updated', 'coupon', $coupon->id, $old, $data);

        return ApiResponse::success($this->present($coupon));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Coupon $coupon): array
    {
        $req = $coupon === null ? 'required' : 'sometimes';
        if ($request->has('code')) {
            $request->merge(['code' => CouponService::normalize((string) $request->input('code'))]);
        }

        return $request->validate([
            'code' => [$req, 'string', 'max:40', 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => [$req, 'in:percent,fixed,free_shipping'],
            'value' => ['required_unless:type,free_shipping', 'nullable', 'numeric', 'min:0', Rule::when($request->input('type') === 'percent', ['max:90'])],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'countries' => ['sometimes', 'array'],
            'countries.*' => [Rule::in((array) config('gulfcart.countries'))],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]) + ($request->input('type') === 'free_shipping' ? ['value' => 0] : []);
    }

    /** @return array<string, mixed> */
    private function present(Coupon $c): array
    {
        return $c->only(['id', 'code', 'description', 'type', 'value', 'max_discount', 'min_subtotal', 'countries', 'usage_limit', 'per_customer_limit', 'is_active'])
            + [
                'starts_at' => $c->starts_at?->toIso8601String(), 'ends_at' => $c->ends_at?->toIso8601String(),
                'used' => (int) ($c->getAttribute('used') ?? 0), 'discount_given' => (string) ($c->getAttribute('discount_given') ?? '0'),
            ];
    }
}
