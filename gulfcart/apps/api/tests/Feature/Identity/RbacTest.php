<?php

namespace Tests\Feature\Identity;

use Tests\TestCase;

final class RbacTest extends TestCase
{
    public function test_guests_are_unauthenticated_on_admin_routes(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/admin/users'), 401, 'UNAUTHENTICATED');
    }

    public function test_customers_are_forbidden_on_admin_routes(): void
    {
        $token = $this->customer()->createToken('t')->plainTextToken;

        $this->assertErrorCode($this->withToken($token)->getJson('/api/v1/admin/users'), 403, 'FORBIDDEN');
    }

    public function test_staff_without_the_permission_is_forbidden(): void
    {
        $token = $this->verifiedStaffToken($this->staff(['support']));

        $this->assertErrorCode($this->withToken($token)->getJson('/api/v1/admin/users'), 403, 'FORBIDDEN');
    }

    public function test_admin_with_users_manage_can_list_users(): void
    {
        $this->customer();
        $token = $this->verifiedStaffToken($this->staff(['admin']));

        $this->withToken($token)->getJson('/api/v1/admin/users?type=customer')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.type', 'customer');
    }

    public function test_admin_cannot_manage_roles_but_super_admin_can(): void
    {
        $target = $this->staff(['support'], mfa: false);

        $adminToken = $this->verifiedStaffToken($this->staff(['admin']));
        $this->assertErrorCode(
            $this->withToken($adminToken)->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['finance']]),
            403, 'FORBIDDEN',
        );

        $this->resetAuth();
        $superToken = $this->verifiedStaffToken($this->staff(['super_admin']));
        $this->withToken($superToken)->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['finance']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['finance']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.roles_updated', 'entity_id' => $target->id]);
    }

    public function test_staff_roles_cannot_be_given_to_customers(): void
    {
        $customer = $this->customer();
        $token = $this->verifiedStaffToken($this->staff(['super_admin']));

        $this->assertErrorCode(
            $this->withToken($token)->putJson("/api/v1/admin/users/{$customer->id}/roles", ['roles' => ['admin']]),
            422, 'VALIDATION_FAILED',
        );
    }

    public function test_unknown_roles_are_rejected(): void
    {
        $target = $this->staff(['support'], mfa: false);
        $token = $this->verifiedStaffToken($this->staff(['super_admin']));

        $this->assertErrorCode(
            $this->withToken($token)->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['wizard']]),
            422, 'VALIDATION_FAILED',
        );
    }
}
