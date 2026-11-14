<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class AdminScreensTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_order_detail_has_customer_shipments_and_procurement_is_filterable_by_order(): void
    {
        ['variant' => $variant] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$variant, 1]], $auth);
        $this->payOrder($order, $auth);
        ['variant' => $other] = $this->sellableProduct(['AE']);
        $second = $this->placeOrder([[$other, 1]], $this->customerAuth());
        $this->payOrder($second, $auth);

        $staff = $this->asStaff(['operations']);
        $this->getJson("/api/v1/admin/orders/{$order->id}", $staff)->assertOk()
            ->assertJsonPath('data.country_code', 'AE')
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonStructure(['data' => ['customer' => ['name', 'email'], 'shipments', 'items', 'totals']]);
        $this->getJson('/api/v1/admin/orders?country=SA', $staff)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/orders?country=AE', $staff)->assertOk()->assertJsonCount(2, 'data');

        $tasks = $this->getJson("/api/v1/admin/procurement?order_id={$order->id}&status=", $staff)->assertOk()->json('data');
        $this->assertCount(1, $tasks);
        $this->assertSame($order->id, $tasks[0]['supplier_order']['order_id']);
    }

    public function test_owner_invites_staff_who_receives_a_password_link_and_can_be_disabled(): void
    {
        Notification::fake();
        $admin = $this->asStaff(['super_admin']);

        $id = $this->postJson('/api/v1/admin/users', ['name' => 'Huda', 'email' => 'Huda@Example.com', 'roles' => ['catalog_manager']], $admin)
            ->assertCreated()->assertJsonPath('data.type', 'staff')->assertJsonPath('data.roles', ['catalog_manager'])->json('data.id');
        $user = User::query()->findOrFail($id);
        $this->assertSame('huda@example.com', $user->email);
        Notification::assertSentTo($user, ResetPassword::class);

        $this->patchJson("/api/v1/admin/users/{$id}/status", ['status' => 'suspended'], $this->asStaff(['super_admin']))->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->getJson('/api/v1/admin/users?type=staff&q=huda', $this->asStaff(['super_admin']))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_only_owner_can_invite_another_owner_and_customers_roles_are_validated(): void
    {
        // admin lacks roles.manage, so inviting is forbidden altogether
        $this->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['support']], $this->asStaff(['admin']))->assertForbidden();
        $this->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['customer']], $this->asStaff(['super_admin']))->assertUnprocessable();
    }
}
