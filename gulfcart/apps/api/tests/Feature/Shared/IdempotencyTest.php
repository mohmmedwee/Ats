<?php

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    private int $executions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/charge', function () {
            $this->executions++;

            return response()->json(['success' => true, 'data' => ['execution' => $this->executions]], 201);
        });
        Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/boom', fn () => response()->json(['success' => false], 503));
    }

    public function test_key_is_required(): void
    {
        $this->assertErrorCode($this->postJson('/api/v1/_test/charge', ['amount' => '10.00']), 400, 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_repeated_request_executes_once_and_replays_the_response(): void
    {
        $headers = ['Idempotency-Key' => 'order-key-0000000001'];

        $first = $this->postJson('/api/v1/_test/charge', ['amount' => '10.00', 'currency' => 'AED'], $headers)->assertCreated();
        $second = $this->postJson('/api/v1/_test/charge', ['currency' => 'AED', 'amount' => '10.00'], $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->executions);
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_same_key_with_a_different_body_is_rejected(): void
    {
        $headers = ['Idempotency-Key' => 'order-key-0000000002'];
        $this->postJson('/api/v1/_test/charge', ['amount' => '10.00'], $headers)->assertCreated();

        $this->assertErrorCode($this->postJson('/api/v1/_test/charge', ['amount' => '99.00'], $headers), 409, 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(1, $this->executions);
    }

    public function test_server_errors_are_not_stored_so_the_client_can_retry(): void
    {
        $headers = ['Idempotency-Key' => 'order-key-0000000003'];
        $this->postJson('/api/v1/_test/boom', [], $headers)->assertStatus(503);

        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_an_in_progress_key_returns_conflict(): void
    {
        \DB::table('idempotency_keys')->insert([
            'id' => (string) \Str::uuid7(), 'key' => 'order-key-0000000004', 'user_id' => null,
            'endpoint' => 'POST api/v1/_test/charge', 'request_hash' => hash('sha256', '{"amount":"10.00"}'),
            'locked_until' => now()->addMinute(), 'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);

        $this->assertErrorCode(
            $this->postJson('/api/v1/_test/charge', ['amount' => '10.00'], ['Idempotency-Key' => 'order-key-0000000004']),
            409, 'CONFLICT',
        );
        $this->assertSame(0, $this->executions);
    }
}
