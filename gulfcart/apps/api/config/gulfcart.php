<?php

return [
    'locales' => array_values(array_filter(explode(',', (string) env('SUPPORTED_LOCALES', 'en,ar')))),
    'default_locale' => env('DEFAULT_LOCALE', 'en'),
    'countries' => array_values(array_filter(explode(',', (string) env('SUPPORTED_COUNTRIES', 'AE,SA')))),
    'default_country' => env('DEFAULT_COUNTRY', 'AE'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost'),
    'mfa_issuer' => env('MFA_ISSUER', 'GulfCart'),

    // Local development only (DevSuperAdminSeeder).
    'dev_admin' => [
        'email' => env('GULFCART_DEV_ADMIN_EMAIL'),
        'password' => env('GULFCART_DEV_ADMIN_PASSWORD'),
    ],

];
