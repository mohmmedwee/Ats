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

    'allow_mock_supplier_in_production' => (bool) env('ALLOW_MOCK_SUPPLIER_IN_PRODUCTION', false),

    // Local development only (DevSuperAdminSeeder).
    'dev_admin' => [
        'email' => env('GULFCART_DEV_ADMIN_EMAIL'),
        'password' => env('GULFCART_DEV_ADMIN_PASSWORD'),
    ],

];
