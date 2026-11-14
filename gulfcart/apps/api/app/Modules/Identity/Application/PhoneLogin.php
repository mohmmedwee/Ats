<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Domain\UserType;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in for customers with a mobile number and an SMS code. A first sign-in creates
 * the account (express checkout). Codes are hashed, expire after 10 minutes, allow 5 attempts and
 * only the newest code is valid. Staff accounts cannot use this path (they need password + MFA).
 */
final class PhoneLogin
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsSender $sms) {}

    public function start(string $phone, string $locale): void
    {
        $user = User::query()->where('phone', $phone)->first();
        if ($user !== null && ($user->isStaff() || ! $user->isActive())) {
            // Same response as for a customer, so the endpoint does not reveal staff numbers.
            return;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($phone, $code, $user) {
            DB::table('otp_codes')->where('phone', $phone)->where('purpose', 'login')->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('otp_codes')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $user?->id, 'phone' => $phone, 'purpose' => 'login',
                'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'created_at' => now(),
            ]);
        });

        $this->sms->send($phone, (string) __('errors.otp_message', ['code' => $code, 'minutes' => self::TTL_MINUTES], $user->preferred_locale ?? $locale));
    }

    /** @return array{0: User, 1: bool} the user and whether the account was just created */
    public function verify(string $phone, string $code, ?string $name, string $locale): array
    {
        // Return an outcome instead of throwing inside, so failed-attempt counters are committed.
        $result = DB::transaction(function () use ($phone, $code, $name, $locale): ?array {
            $row = DB::table('otp_codes')->where('phone', $phone)->where('purpose', 'login')
                ->whereNull('consumed_at')->orderByDesc('created_at')->lockForUpdate()->first();
            if ($row === null || now()->greaterThan($row->expires_at) || $row->attempts >= self::MAX_ATTEMPTS) {
                return null;
            }
            if (! Hash::check($code, $row->code_hash)) {
                DB::table('otp_codes')->where('id', $row->id)->increment('attempts');

                return null;
            }
            DB::table('otp_codes')->where('id', $row->id)->update(['consumed_at' => now()]);

            $user = User::query()->where('phone', $phone)->lockForUpdate()->first();
            $created = false;
            if ($user === null) {
                $user = User::query()->create([
                    'name' => trim((string) $name) !== '' ? trim((string) $name) : '',
                    'phone' => $phone, 'type' => UserType::Customer, 'status' => 'active', 'preferred_locale' => $locale,
                ]);
                $user->assignRole('customer');
                $created = true;
            }
            if ($user->isStaff() || ! $user->isActive()) {
                return null;
            }
            $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now()])->save();

            return [$user, $created];
        });

        if ($result === null) {
            throw new ApiException('OTP_INVALID', 422);
        }

        return $result;
    }
}
