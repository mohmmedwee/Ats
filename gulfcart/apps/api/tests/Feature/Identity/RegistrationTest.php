<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Infrastructure\Models\User;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sara Ahmed',
            'email' => 'Sara@Example.com',
            'password' => 'Secret-pass-1',
            'password_confirmation' => 'Secret-pass-1',
        ], $overrides);
    }

    public function test_customer_registers_with_email_and_gets_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('data.type', 'customer')
            ->assertJsonPath('data.roles', ['customer'])
            ->assertJsonPath('data.permissions', ['orders.view_own']);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.registered']);
    }

    public function test_customer_registers_with_phone_only(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => null, 'phone' => '+971501234567']))
            ->assertCreated()
            ->assertJsonPath('data.phone', '+971501234567')
            ->assertJsonPath('data.email', null);
    }

    public function test_email_or_phone_is_required(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload(['email' => null]));

        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'phone']]]]);
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'sara@example.com']);

        $this->assertErrorCode($this->postJson('/api/v1/auth/register', $this->payload()), 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
    }

    public function test_weak_password_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'short', 'password_confirmation' => 'short']));

        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
    }

    public function test_invalid_phone_format_is_rejected(): void
    {
        $this->assertErrorCode(
            $this->postJson('/api/v1/auth/register', $this->payload(['email' => null, 'phone' => '0501234567'])),
            422, 'VALIDATION_FAILED',
        );
    }

    public function test_validation_messages_are_localized_in_arabic(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['password' => 'x'], ['Accept-Language' => 'ar']);

        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'البيانات المدخلة غير صحيحة.')
            ->assertJsonPath('error.details.fields.name.0', 'حقل الاسم مطلوب.')
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_spa_registration_uses_the_session_instead_of_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload(), self::SPA)->assertCreated();

        $this->assertNull($response->json('data.token'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'sara@example.com')->firstOrFail(), 'web');
    }
}
