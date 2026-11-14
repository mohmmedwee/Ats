<?php

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Countries\Providers\CountriesServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Suppliers\Providers\SuppliersServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CountriesServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    SuppliersServiceProvider::class,
];
