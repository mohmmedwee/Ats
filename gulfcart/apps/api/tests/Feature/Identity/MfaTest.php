<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Infrastructure\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class MfaTest extends TestCase
{
    private function pendingToken(User $user): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'Password-123'])
            ->assertOk()->json('data.token');
        $this->resetAuth();

        return $token;
    }

    public function test_staff_enrols_in_mfa_and_gets_recovery_codes(): void
    {
        $staff = $this->staff(['admin'], mfa: false);
        $token = $this->pendingToken($staff);

        $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/setup')
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url']])
            ->json('data.secret');

        $this->resetAuth();
        $response = $this->withToken($token)->postJson('/api/v1/auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()
            ->assertJsonCount(8, 'data.recovery_codes');

        $this->assertNotNull($staff->refresh()->mfa_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_enabled', 'entity_id' => $staff->id]);

        // The new token is fully verified.
        $this->resetAuth();
        $this->withToken($response->json('data.token'))->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_confirm_rejects_a_wrong_code(): void
    {
        $staff = $this->staff(['admin'], mfa: false);
        $token = $this->pendingToken($staff);
        $this->withToken($token)->postJson('/api/v1/auth/mfa/setup')->assertOk();

        $this->resetAuth();
        $this->assertErrorCode($this->withToken($token)->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000']), 422, 'MFA_CODE_INVALID');
        $this->assertNull($staff->refresh()->mfa_confirmed_at);
    }

    public function test_admin_routes_require_mfa_enrolment(): void
    {
        $staff = $this->staff(['admin'], mfa: false);

        $this->assertErrorCode($this->withToken($this->pendingToken($staff))->getJson('/api/v1/admin/users'), 403, 'MFA_SETUP_REQUIRED');
    }

    public function test_admin_routes_reject_a_token_that_has_not_passed_the_challenge(): void
    {
        $staff = $this->staff(['admin']);

        $this->postJson('/api/v1/auth/login', ['login' => $staff->email, 'password' => 'Password-123'])
            ->assertJsonPath('data.mfa_required', true);
        $this->resetAuth();

        $this->assertErrorCode($this->withToken($this->pendingToken($staff))->getJson('/api/v1/admin/users'), 401, 'MFA_REQUIRED');
    }

    public function test_challenge_exchanges_the_pending_token_for_a_verified_one(): void
    {
        $staff = $this->staff(['admin']);
        $pending = $this->pendingToken($staff);

        $verified = $this->withToken($pending)->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->otp($staff)])
            ->assertOk()->json('data.token');

        $this->resetAuth();
        $this->withToken($verified)->getJson('/api/v1/admin/users')->assertOk();

        $this->resetAuth();
        $this->assertErrorCode($this->withToken($pending)->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $staff = $this->staff(['admin']);
        $code = $this->otp($staff);

        $this->withToken($this->pendingToken($staff))->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])->assertOk();

        $this->resetAuth();
        $this->assertErrorCode(
            $this->withToken($this->pendingToken($staff))->postJson('/api/v1/auth/mfa/challenge', ['code' => $code]),
            422, 'MFA_CODE_INVALID',
        );
    }

    public function test_recovery_code_works_exactly_once(): void
    {
        $staff = $this->staff(['admin'], mfa: false);
        $token = $this->pendingToken($staff);
        $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/setup')->json('data.secret');
        $this->resetAuth();
        $codes = $this->withToken($token)->postJson('/api/v1/auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->json('data.recovery_codes');
        $this->resetAuth();

        $this->withToken($this->pendingToken($staff))->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => $codes[0]])->assertOk();
        $this->resetAuth();
        $this->assertErrorCode(
            $this->withToken($this->pendingToken($staff))->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => $codes[0]]),
            422, 'MFA_CODE_INVALID',
        );
        $this->assertCount(7, $staff->refresh()->mfa_recovery_codes ?? []);
    }

    public function test_spa_session_passes_mfa_through_the_challenge(): void
    {
        $staff = $this->staff(['admin']);

        $this->postJson('/api/v1/auth/login', ['login' => $staff->email, 'password' => 'Password-123'], self::SPA)->assertOk();
        $this->assertErrorCode($this->getJson('/api/v1/admin/users', self::SPA), 401, 'MFA_REQUIRED');

        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->otp($staff)], self::SPA)->assertOk();
        $this->getJson('/api/v1/admin/users', self::SPA)->assertOk();
    }

    public function test_customers_cannot_use_mfa_endpoints(): void
    {
        $customer = $this->customer();

        $this->assertErrorCode($this->actingAs($customer, 'sanctum')->postJson('/api/v1/auth/mfa/setup'), 403, 'FORBIDDEN');
    }
}
