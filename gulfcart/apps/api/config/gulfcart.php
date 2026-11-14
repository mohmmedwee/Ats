<?php

return [
    'locales' => array_values(array_filter(explode(',', (string) env('SUPPORTED_LOCALES', 'en,ar')))),
    'default_locale' => env('DEFAULT_LOCALE', 'en'),
    'countries' => array_values(array_filter(explode(',', (string) env('SUPPORTED_COUNTRIES', 'AE,SA')))),
    'default_country' => env('DEFAULT_COUNTRY', 'AE'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost'),
    'mfa_issuer' => env('MFA_ISSUER', 'GulfCart'),

    // Filesystem disk for product images (S3 in production).
    'catalog_disk' => env('CATALOG_DISK', 'public'),

    // Authorized supplier connectors: connector_type => class implementing SupplierConnector.
    // Add an entry only after written authorization (docs/PROCUREMENT_WORKFLOW.md §5).
    'supplier_connectors' => [],

    'pricing' => [
        // Gateway fee charged on the gross (VAT-inclusive) amount.
        'payment_fee_percent' => env('PAYMENT_FEE_PERCENT', '2.9'),
        'payment_fee_fixed' => ['AE' => '0', 'SA' => '0'],
        // Per-unit packaging/support/marketing allocation in the sale currency.
        'operational_allocation' => ['AE' => '0', 'SA' => '0'],
        // Buffer on non-pegged currency conversion.
        'fx_buffer_percent' => env('FX_BUFFER_PERCENT', '1.5'),
        'quote_validity_minutes' => (int) env('QUOTE_VALIDITY_MINUTES', 30),
    ],

    'fx' => [
        'max_age_hours' => (int) env('FX_MAX_AGE_HOURS', 24),
        // Used by ConfiguredRateProvider. USD pegs are official; other pairs are placeholders
        // until a live provider is bound (docs/PRICING_ENGINE.md §6).
        'rates' => [
            'USD' => ['AED' => '3.6725', 'SAR' => '3.7500'],
            'EUR' => ['AED' => '4.0000', 'SAR' => '4.0800'],
            'GBP' => ['AED' => '4.6500', 'SAR' => '4.7500'],
            'CNY' => ['AED' => '0.5100', 'SAR' => '0.5200'],
            'AED' => ['SAR' => '1.0211'],
        ],
    ],

    'orders' => [
        'payment_window_minutes' => (int) env('ORDER_PAYMENT_WINDOW_MINUTES', 60),
    ],

    'payments' => [
        // Decision D2 pending: until a provider is contracted, the sandbox gateway is used.
        'provider' => env('PAYMENT_PROVIDER', 'sandbox'),
        'gateways' => [],  // provider name => class implementing PaymentGateway
        'sandbox' => ['secret' => env('PAYMENT_SANDBOX_SECRET', 'sandbox-dev-secret-change-me')],
        'allow_sandbox_in_production' => (bool) env('ALLOW_SANDBOX_PAYMENTS_IN_PRODUCTION', false),
        'webhook_tolerance_seconds' => 300,
        // Staff refunds above this amount need a second approver (four-eyes).
        'refund_approval_threshold' => ['AED' => '500', 'SAR' => '500'],
    ],

    'returns' => [
        // Per-country windows live in countries.settings.returns.window_days (legal review pending).
        'default_window_days' => 14,
    ],

    'notifications' => [
        'operations_email' => env('OPERATIONS_ALERT_EMAIL', 'operations@gulfcart.local'),
    ],

    'procurement' => [
        // Do not buy when the supplier's current cost is this much above the expected cost.
        'max_cost_increase_percent' => env('PROCUREMENT_MAX_COST_INCREASE_PERCENT', '5'),
        'retry_backoff_minutes' => [1, 5, 15, 60, 240],
        'stale_lock_minutes' => 10,
    ],

    'allow_mock_supplier_in_production' => (bool) env('ALLOW_MOCK_SUPPLIER_IN_PRODUCTION', false),

    // Local development only (DevSuperAdminSeeder).
    'dev_admin' => [
        'email' => env('GULFCART_DEV_ADMIN_EMAIL'),
        'password' => env('GULFCART_DEV_ADMIN_PASSWORD'),
    ],

];
