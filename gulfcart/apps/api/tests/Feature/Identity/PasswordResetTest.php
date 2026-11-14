<?php

namespace Tests\Feature\Identity;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    public function test_reset_link_is_sent_and_response_does_not_reveal_accounts(): void
    {
        Notification::fake();
        $user = $this->customer(['email' => 'sara@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'sara@example.com'])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('data'), $unknown->json('data'));
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://spa.gulfcart.test/reset-password?token=');
        });
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_password_can_be_reset_with_a_valid_token_and_old_tokens_are_revoked(): void
    {
        Notification::fake();
        $user = $this->customer(['email' => 'sara@example.com']);
        $user->createToken('old');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'sara@example.com']);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'sara@example.com',
            'password' => 'Brand-new-pass-2',
            'password_confirmation' => 'Brand-new-pass-2',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/auth/login', ['login' => 'sara@example.com', 'password' => 'Brand-new-pass-2'])->assertOk();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->customer(['email' => 'sara@example.com']);

        $this->assertErrorCode($this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'sara@example.com',
            'password' => 'Brand-new-pass-2',
            'password_confirmation' => 'Brand-new-pass-2',
        ]), 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
    }
}
