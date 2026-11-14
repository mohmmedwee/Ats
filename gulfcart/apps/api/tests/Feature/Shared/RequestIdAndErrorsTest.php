<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

final class RequestIdAndErrorsTest extends TestCase
{
    public function test_request_id_is_generated_and_returned_in_header_and_meta(): void
    {
        $response = $this->getJson('/api/v1/countries')->assertOk();

        $id = $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^req_[0-9a-z]{26}$/', (string) $id);
        $response->assertJsonPath('meta.request_id', $id);
    }

    public function test_a_safe_incoming_request_id_is_propagated(): void
    {
        $this->getJson('/api/v1/countries', ['X-Request-Id' => 'edge-1234abcd'])
            ->assertHeader('X-Request-Id', 'edge-1234abcd')
            ->assertJsonPath('meta.request_id', 'edge-1234abcd');
    }

    public function test_an_unsafe_incoming_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/countries', ['X-Request-Id' => "bad id\nwith newline"]);

        $this->assertStringStartsWith('req_', (string) $response->headers->get('X-Request-Id'));
    }

    public function test_unknown_routes_use_the_error_envelope(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/does-not-exist'), 404, 'NOT_FOUND');
    }

    public function test_error_messages_are_localized(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/auth/me', ['Accept-Language' => 'ar']), 401, 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'يجب تسجيل الدخول.');
    }

    public function test_wrong_method_uses_the_error_envelope(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/auth/login'), 405, 'METHOD_NOT_ALLOWED');
    }
}
