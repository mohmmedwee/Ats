<?php

use App\Modules\Suppliers\Application\Connectors\Cj\CjDropshippingConnector;

return [
    'locales' => array_values(array_filter(explode(',', (string) env('SUPPORTED_LOCALES', 'en,ar')))),
    'default_locale' => env('DEFAULT_LOCALE', 'en'),
    'countries' => array_values(array_filter(explode(',', (string) env('SUPPORTED_COUNTRIES', 'AE,SA')))),
    'default_country' => env('DEFAULT_COUNTRY', 'AE'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost'),
    'mfa_issuer' => env('MFA_ISSUER', 'GulfCart'),

    // Filesystem disk for product images (S3 in production).
    'catalog_disk' => env('CATALOG_DISK', 'public'),

    // Supplier connectors: connector_type => class implementing SupplierConnector.
    // Registering a type does not activate anyone: a supplier using it still needs an approved
    // authorization record before it can be activated (docs/PROCUREMENT_WORKFLOW.md §5).
    'supplier_connectors' => [
        'cj' => CjDropshippingConnector::class,
    ],

    // SMS for sign-in codes: log (development) | twilio | unifonic.
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'log'),
        'sender' => env('SMS_SENDER', 'GulfCart'),
        'twilio_sid' => env('TWILIO_ACCOUNT_SID'),
        'twilio_token' => env('TWILIO_AUTH_TOKEN'),
        'unifonic_app_sid' => env('UNIFONIC_APP_SID'),
    ],

    // WhatsApp Business (Meta Cloud API). Each mapped template must exist and be approved in
    // WhatsApp Manager with the same name, in English (en) and Arabic (ar), with body variables
    // {{1}}, {{2}}… in the order listed under params, and an optional URL button ending in {{1}}
    // that receives the order/cart path (docs/NOTIFICATIONS.md).
    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'none'), // none | meta
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'templates' => [
            'order_confirmed' => ['name' => 'gc_order_confirmed', 'params' => ['name', 'order', 'total']],
            'order_shipped' => ['name' => 'gc_order_shipped', 'params' => ['name', 'order', 'carrier', 'tracking']],
            'order_delivered' => ['name' => 'gc_order_delivered', 'params' => ['name', 'order']],
            'order_cancelled' => ['name' => 'gc_order_cancelled', 'params' => ['name', 'order']],
            'refund_issued' => ['name' => 'gc_refund_issued', 'params' => ['name', 'amount', 'order']],
            'cart_reminder' => ['name' => 'gc_cart_reminder', 'params' => ['name', 'count']],
        ],
    ],

    // Customer support channels shown on the storefront (contact page, WhatsApp button).
    'support' => [
        'email' => env('SUPPORT_EMAIL', 'support@gulfcart.example'),
        'whatsapp' => env('SUPPORT_WHATSAPP'), // E.164 without +, e.g. 971500000000
        'hours' => env('SUPPORT_HOURS', 'Sun–Thu 9:00–18:00 (GST)'),
    ],

    // Marketing tags loaded by the storefront only after the visitor accepts cookies.
    'tracking' => [
        'meta_pixel_id' => env('META_PIXEL_ID'),
        'tiktok_pixel_id' => env('TIKTOK_PIXEL_ID'),
        'snap_pixel_id' => env('SNAP_PIXEL_ID'),
        'ga4_id' => env('GA4_MEASUREMENT_ID'),
    ],

    // Machine translation of imported product text to Arabic (drafts, reviewed before publishing).
    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'none'), // none | claude
        'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('TRANSLATION_MODEL', 'claude-opus-5-5'),
    ],

    // CJdropshipping (docs/suppliers/CJ_DROPSHIPPING.md).
    'cj' => [
        'api_key' => env('CJ_API_KEY', ''),
        'base_url' => env('CJ_API_BASE_URL', 'https://developers.cjdropshipping.com/api2.0/v1/'),
        'timeout' => (int) env('CJ_TIMEOUT_SECONDS', 20),
        'from_country' => env('CJ_FROM_COUNTRY', 'CN'),
        // Preferred CJ logistic names in order; the cheapest available is used otherwise.
        'logistics' => env('CJ_LOGISTICS', ''),
        // Pay new CJ orders from the CJ wallet balance automatically.
        'auto_pay' => (bool) env('CJ_AUTO_PAY', true),
        'confirm_before_pay' => (bool) env('CJ_CONFIRM_BEFORE_PAY', true),
        'low_stock_units' => (int) env('CJ_LOW_STOCK_UNITS', 10),
        // Destination used for shipping estimates when the order country is unknown.
        'quote_country' => 'SA',
        'tracking_url' => env('CJ_TRACKING_URL', 'https://www.17track.net/en/track?nums={number}'),
    ],

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
        // 'config' (static rates below) or 'http' (live feed).
        'provider' => env('FX_PROVIDER', 'config'),
        'http' => [
            'url' => env('FX_HTTP_URL', 'https://open.er-api.com/v6/latest/{base}'),
            'bases' => ['USD', 'EUR', 'GBP', 'CNY'],
            'targets' => ['AED', 'SAR'],
            'max_change_percent' => env('FX_MAX_CHANGE_PERCENT', '10'),
        ],
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

    'invoices' => [
        // Placeholder until the legal entity is decided; set per country in countries.settings.seller.
        'seller_legal_name' => env('INVOICE_SELLER_NAME', 'GulfCart (legal entity pending)'),
    ],

    'returns' => [
        // Per-country windows live in countries.settings.returns.window_days (legal review pending).
        'default_window_days' => 14,
    ],

    'notifications' => [
        'operations_email' => env('OPERATIONS_ALERT_EMAIL', 'operations@gulfcart.local'),
        'cart_reminders' => (bool) env('CART_REMINDERS_ENABLED', true),
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
