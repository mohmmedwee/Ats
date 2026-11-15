<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Promotions\Application\LoyaltyService;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Modules\Promotions\Infrastructure\Models\CouponRedemption;
use App\Modules\Promotions\Infrastructure\Models\LoyaltyAccount;
use App\Modules\Promotions\Infrastructure\Models\LoyaltyTransaction;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Customer rewards: balance, history, converting points, referral code; plus staff summary and adjustments. */
final class LoyaltyController
{
    public function __construct(private readonly LoyaltyService $loyalty, private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $country = $this->country($request);
        $account = $this->loyalty->account($userId);
        $s = LoyaltyService::settings();
        $used = CouponRedemption::query()->where('status', 'active')->pluck('coupon_id');
        $codes = Coupon::query()->where('user_id', $userId)->where('is_active', true)->whereNotIn('id', $used)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest()->get();

        return ApiResponse::success([
            'enabled' => LoyaltyService::enabled(),
            'balance' => $account->balance,
            'balance_value' => ['amount' => Decimal::round(Decimal::mul((string) $account->balance, $s['point_value'], 4), 2), 'currency' => $country->currency],
            'points_per_unit' => $s['points_per_unit'],
            'point_value' => $s['point_value'],
            'min_redeem' => $s['min_redeem'],
            'referral' => [
                'code' => $account->referral_code,
                'referrer_points' => $s['referrer_points'],
                'friend_percent' => $s['friend_percent'],
                'friends_joined' => LoyaltyAccount::query()->where('referred_by', $userId)->count(),
                'can_enter_code' => $account->referred_by === null && ! LoyaltyTransaction::query()->where('user_id', $userId)->where('type', 'earn')->exists(),
            ],
            'codes' => $codes->map(fn (Coupon $c) => [
                'code' => $c->code, 'source' => $c->source, 'type' => $c->type, 'value' => (string) $c->value,
                'max_discount' => $c->max_discount !== null ? (string) $c->max_discount : null, 'ends_at' => $c->ends_at?->toIso8601String(),
            ])->all(),
            'history' => LoyaltyTransaction::query()->where('user_id', $userId)->latest('created_at')->limit(30)->get()
                ->map(fn (LoyaltyTransaction $t) => ['type' => $t->type, 'points' => $t->points, 'note' => $t->note, 'created_at' => $t->created_at->toIso8601String()])->all(),
        ]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $country = $this->country($request);
        $coupon = $this->loyalty->redeem((string) $request->user()?->getAuthIdentifier(), (int) $data['points'], $country->code, $country->currency)->refresh();

        return ApiResponse::success(['code' => $coupon->code, 'value' => ['amount' => (string) $coupon->value, 'currency' => $country->currency], 'ends_at' => $coupon->ends_at?->toIso8601String()], 201);
    }

    public function referral(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $coupon = $this->loyalty->applyReferral((string) $request->user()?->getAuthIdentifier(), $data['code'])->refresh();

        return ApiResponse::success(['code' => $coupon->code, 'percent' => (string) $coupon->value, 'ends_at' => $coupon->ends_at?->toIso8601String()], 201);
    }

    /** Staff: outstanding points, their value, and the top holders. */
    public function summary(): JsonResponse
    {
        $s = LoyaltyService::settings();
        $outstanding = (int) LoyaltyAccount::query()->sum('balance');
        $byType = LoyaltyTransaction::query()->groupBy('type')->selectRaw('type, sum(points) as total')->pluck('total', 'type');
        $top = LoyaltyAccount::query()->where('balance', '>', 0)->orderByDesc('balance')->limit(20)
            ->leftJoin('users', 'users.id', '=', 'loyalty_accounts.user_id')
            ->get(['loyalty_accounts.user_id', 'loyalty_accounts.balance', 'loyalty_accounts.referral_code', 'users.name', 'users.email', 'users.phone']);

        return ApiResponse::success([
            'settings' => $s,
            'outstanding_points' => $outstanding,
            'outstanding_value' => Decimal::round(Decimal::mul((string) $outstanding, $s['point_value'], 4), 2),
            'totals' => collect(['earn', 'reversal', 'redeem', 'referral_bonus', 'adjustment'])->mapWithKeys(fn ($t) => [$t => (int) ($byType[$t] ?? 0)])->all(),
            'referrals' => LoyaltyAccount::query()->whereNotNull('referred_by')->count(),
            'top' => $top->map(fn ($a) => ['user_id' => $a->user_id, 'name' => $a->name, 'contact' => $a->email ?? $a->phone, 'balance' => (int) $a->balance, 'referral_code' => $a->referral_code])->all(),
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['required', 'string', 'max:255'],
        ]);
        $account = DB::transaction(fn () => $this->loyalty->adjust($data['user_id'], (int) $data['points'], $data['note']));
        $this->audit->record('loyalty.adjusted', 'user', $data['user_id'], null, $data);

        return ApiResponse::success(['user_id' => $account->user_id, 'balance' => $account->refresh()->balance]);
    }

    private function country(Request $request): CountryData
    {
        /** @var CountryData */
        return $request->attributes->get('country');
    }
}
