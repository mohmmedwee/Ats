<?php

namespace App\Modules\Identity\Domain;

/**
 * Permission and role catalogue (docs/SECURITY_SPEC.md §2).
 * Policies and routes check permissions, never role names.
 */
final class Permissions
{
    public const ORDERS_VIEW_OWN = 'orders.view_own';

    public const ORDERS_VIEW_ANY = 'orders.view_any';

    public const CATALOG_MANAGE = 'catalog.manage';

    public const CATALOG_PUBLISH = 'catalog.publish';

    public const SUPPLIERS_MANAGE = 'suppliers.manage';

    public const PROCUREMENT_VIEW = 'procurement.view';

    public const PROCUREMENT_ACT = 'procurement.act';

    public const PRICING_MANAGE = 'pricing.manage';

    public const REFUNDS_REQUEST = 'refunds.request';

    public const REFUNDS_APPROVE = 'refunds.approve';

    public const COUNTRIES_MANAGE = 'countries.manage';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const ROLE_CUSTOMER = 'customer';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_filter(
            (new \ReflectionClass(self::class))->getConstants(),
            static fn ($v, string $k) => ! str_starts_with($k, 'ROLE_'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** @return array<string, list<string>> role => permissions */
    public static function roles(): array
    {
        $all = self::all();
        $admin = array_values(array_diff($all, [self::ROLES_MANAGE, self::ORDERS_VIEW_OWN]));

        return [
            self::ROLE_CUSTOMER => [self::ORDERS_VIEW_OWN],
            'support' => [self::ORDERS_VIEW_ANY, self::REFUNDS_REQUEST],
            'operations' => [self::ORDERS_VIEW_ANY, self::PROCUREMENT_VIEW, self::PROCUREMENT_ACT, self::REFUNDS_REQUEST],
            'catalog_manager' => [self::CATALOG_MANAGE, self::CATALOG_PUBLISH],
            'finance' => [self::ORDERS_VIEW_ANY, self::PROCUREMENT_VIEW, self::PRICING_MANAGE, self::REFUNDS_REQUEST, self::REFUNDS_APPROVE, self::AUDIT_VIEW],
            'admin' => $admin,
            'super_admin' => $all,
        ];
    }

    /** @return list<string> */
    public static function staffRoles(): array
    {
        return array_values(array_diff(array_keys(self::roles()), [self::ROLE_CUSTOMER]));
    }
}
