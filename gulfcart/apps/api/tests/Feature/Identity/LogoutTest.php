<?php

namespace Tests\Feature\Identity;

use Tests\TestCase;

final class LogoutTest extends TestCase
{
    public function test_logout_revokes_the_token(): void
    {
        $user = $this->customer();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);

        $this->resetAuth();
        $this->assertErrorCode($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_spa_logout_ends_the_session(): void
    {
        $user = $this->customer();
        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'Password-123'], self::SPA)->assertOk();

        $this->postJson('/api/v1/auth/logout', [], self::SPA)->assertNoContent();

        $this->assertGuest('web');
    }
}
