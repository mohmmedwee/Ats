<?php

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_liveness_has_no_dependencies(): void
    {
        $this->getJson('/api/v1/health/live')->assertOk()->assertJsonPath('data.status', 'ok');
    }

    public function test_readiness_reports_all_dependencies(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.checks', ['postgres' => 'ok', 'redis' => 'ok', 'queue' => 'ok']);
    }

    public function test_readiness_is_503_when_redis_is_down(): void
    {
        config(['database.redis.default.port' => 1, 'database.redis.default.timeout' => 0.2]);
        Redis::purge('default');

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'down')
            ->assertJsonPath('data.checks.redis', 'down')
            ->assertJsonPath('data.checks.postgres', 'ok');
    }

    public function test_health_command_exit_code(): void
    {
        $this->artisan('app:health --ready')->assertSuccessful();
    }

    public function test_queue_probe_round_trips_with_the_sync_queue(): void
    {
        $this->artisan('app:queue-probe --wait=1')->assertSuccessful();
    }
}
