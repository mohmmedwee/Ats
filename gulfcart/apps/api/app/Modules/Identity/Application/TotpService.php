<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP (RFC 6238) enrolment and verification with replay protection and recovery codes. */
final class TotpService
{
    private const WINDOW = 1;

    private const RECOVERY_CODES = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('gulfcart.mfa_issuer', 'GulfCart'), (string) ($user->email ?? $user->phone), $secret);
    }

    /** Verifies a code and records its time step so it cannot be replayed. */
    public function verify(User $user, string $code): bool
    {
        $secret = $user->mfa_secret;
        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, $user->mfa_last_timestamp, self::WINDOW);
        if ($timestamp === false) {
            return false;
        }

        $user->forceFill(['mfa_last_timestamp' => $timestamp === true ? $this->google2fa->getTimestamp() : $timestamp])->save();

        return true;
    }

    /**
     * Generates new recovery codes, stores their hashes and returns the plain codes once.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        $user->forceFill(['mfa_recovery_codes' => array_map(static fn (string $c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    /** Consumes a recovery code if it matches. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->mfa_recovery_codes ?? [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check(Str::lower(trim($code)), $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }
}
