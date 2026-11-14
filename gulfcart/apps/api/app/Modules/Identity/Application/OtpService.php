<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** One-time codes for phone verification: hashed, 10-minute expiry, 5 attempts, newest code only. */
final class OtpService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsSender $sms) {}

    public function send(User $user, string $phone): void
    {
        if (User::query()->where('phone', $phone)->whereKeyNot($user->id)->exists()) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['phone' => [__('validation.unique', ['attribute' => __('validation.attributes.phone')])]]]);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $phone, $code) {
            DB::table('otp_codes')->where('user_id', $user->id)->where('purpose', 'verify_phone')->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('otp_codes')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'phone' => $phone, 'purpose' => 'verify_phone',
                'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'created_at' => now(),
            ]);
        });

        $this->sms->send($phone, (string) __('errors.otp_message', ['code' => $code, 'minutes' => self::TTL_MINUTES], $user->preferred_locale ?? 'en'));
    }

    public function verify(User $user, string $phone, string $code): void
    {
        // The transaction returns an outcome instead of throwing, so a failed attempt's counter
        // increment is committed (throwing inside would roll it back and allow brute force).
        $ok = DB::transaction(function () use ($user, $phone, $code): bool {
            $row = DB::table('otp_codes')->where('user_id', $user->id)->where('phone', $phone)->where('purpose', 'verify_phone')
                ->whereNull('consumed_at')->orderByDesc('created_at')->lockForUpdate()->first();

            if ($row === null || now()->greaterThan($row->expires_at) || $row->attempts >= self::MAX_ATTEMPTS) {
                return false;
            }
            if (! Hash::check($code, $row->code_hash)) {
                DB::table('otp_codes')->where('id', $row->id)->increment('attempts');

                return false;
            }
            DB::table('otp_codes')->where('id', $row->id)->update(['consumed_at' => now()]);
            $user->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();

            return true;
        });

        if (! $ok) {
            throw new ApiException('OTP_INVALID', 422);
        }
    }
}
