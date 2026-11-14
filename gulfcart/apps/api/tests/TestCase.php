<?php

namespace Tests;

use App\Modules\Identity\Domain\UserType;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Seeds countries and roles (DevSuperAdminSeeder is a no-op outside `local`). */
    protected bool $seed = true;

    /** Header that makes Sanctum treat the request as a stateful SPA request. */
    protected const SPA = ['Referer' => 'http://spa.gulfcart.test/', 'Origin' => 'http://spa.gulfcart.test'];

    protected function customer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('customer');

        return $user;
    }

    /** Staff user with the given roles and, optionally, MFA already enrolled. */
    protected function staff(array $roles = ['admin'], bool $mfa = true): User
    {
        $user = User::factory()->create(['type' => UserType::Staff]);
        $user->syncRoles($roles);

        if ($mfa) {
            $user->forceFill([
                'mfa_secret' => (new Google2FA)->generateSecretKey(32),
                'mfa_confirmed_at' => now(),
            ])->save();
        }

        return $user;
    }

    protected function otp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp((string) $user->refresh()->mfa_secret);
    }

    /** Authorization header for an MFA-verified staff token with the given roles. @return array<string, string> */
    protected function asStaff(array $roles = ['admin']): array
    {
        $token = $this->staff($roles)->createToken('test', ['*'])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    /** Logs a staff user in via the API and completes the MFA challenge; returns a full token. */
    protected function verifiedStaffToken(User $user): string
    {
        $pending = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'Password-123'])
            ->assertOk()->json('data.token');

        $token = $this->withToken($pending)
            ->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->otp($user)])
            ->assertOk()->json('data.token');

        $this->resetAuth();

        return $token;
    }

    /** Forget cached guard users between requests made in one test. */
    protected function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    protected function assertErrorCode(TestResponse $response, int $status, string $code): TestResponse
    {
        return $response->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', $code)
            ->assertJsonStructure(['success', 'error' => ['code', 'message', 'details'], 'meta' => ['request_id']]);
    }
}
