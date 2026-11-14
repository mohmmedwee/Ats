<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Permissions;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    public function test_super_admin_has_every_permission(): void
    {
        $this->assertEqualsCanonicalizing(Permissions::all(), Permissions::roles()['super_admin']);
    }

    public function test_admin_cannot_manage_roles(): void
    {
        $this->assertNotContains(Permissions::ROLES_MANAGE, Permissions::roles()['admin']);
        $this->assertContains(Permissions::USERS_MANAGE, Permissions::roles()['admin']);
    }

    public function test_customer_only_sees_own_orders(): void
    {
        $this->assertSame([Permissions::ORDERS_VIEW_OWN], Permissions::roles()['customer']);
    }

    public function test_every_role_permission_exists_in_the_catalogue(): void
    {
        foreach (Permissions::roles() as $role => $permissions) {
            $this->assertEmpty(array_diff($permissions, Permissions::all()), "Unknown permission in role {$role}");
        }
        $this->assertCount(14, Permissions::all());
    }
}
