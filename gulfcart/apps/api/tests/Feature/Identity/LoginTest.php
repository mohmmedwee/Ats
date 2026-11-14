<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Infrastructure\Models\User;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    public function test_customer_logs_in_with_email_and_calls_me(): void
    {
        $user = $this->customer(['email' => 'sara@example.com']);

        $token = $this->postJson('/api/v1/auth/login', ['login' => 'SARA@example.com', 'password' => 'Password-123'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.mfa_required', false)
            ->assertJsonPath('data.mfa_setup_required', false)
            ->json('data.token');

        $this->resetAuth();
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'sara@example.com');
    }

    public function test_customer_logs_in_with_phone(): void
    {
        $this->customer(['email' => null, 'phone' => '+966501234567']);

        $this->postJson('/api/v1/auth/login', ['login' => '+966501234567', 'password' => 'Password-123'])->assertOk();
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $user = $this->customer();

        $this->assertErrorCode(
            $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'wrong-password']),
            401, 'INVALID_CREDENTIALS',
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'entity_id' => $user->id]);
    }

    public function test_unknown_user_gets_the_same_error(): void
    {
        $this->assertErrorCode(
            $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.com', 'password' => 'whatever-1']),
            401, 'INVALID_CREDENTIALS',
        );
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create();

        $this->assertErrorCode(
            $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'Password-123']),
            403, 'ACCOUNT_DISABLED',
        );
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->customer();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
        }

        $this->assertErrorCode(
            $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'Password-123']),
            429, 'RATE_LIMITED',
        )->assertHeader('Retry-After');
    }

    public function test_me_requires_authentication(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_staff_without_mfa_is_told_to_enrol(): void
    {
        $staff = $this->staff(['admin'], mfa: false);

        $this->postJson('/api/v1/auth/login', ['login' => $staff->email, 'password' => 'Password-123'])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', false)
            ->assertJsonPath('data.mfa_setup_required', true);
    }

    public function test_spa_login_uses_the_session(): void
    {
        $user = $this->customer();

        $response = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'Password-123'], self::SPA)
            ->assertOk();

        $this->assertNull($response->json('data.token'));
        $this->assertAuthenticatedAs($user, 'web');
    }
}
