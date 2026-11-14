<?php

use App\Modules\Analytics\Providers\AnalyticsServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Cart\Providers\CartServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Checkout\Providers\CheckoutServiceProvider;
use App\Modules\Countries\Providers\CountriesServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Invoices\Providers\InvoicesServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\Pricing\Providers\PricingServiceProvider;
use App\Modules\Procurement\Providers\ProcurementServiceProvider;
use App\Modules\Returns\Providers\ReturnsServiceProvider;
use App\Modules\Shipping\Providers\ShippingServiceProvider;
use App\Modules\Suppliers\Providers\SuppliersServiceProvider;
use App\Modules\Tax\Providers\TaxServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CountriesServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    SuppliersServiceProvider::class,
    TaxServiceProvider::class,
    ShippingServiceProvider::class,
    PricingServiceProvider::class,
    CartServiceProvider::class,
    CheckoutServiceProvider::class,
    OrdersServiceProvider::class,
    PaymentsServiceProvider::class,
    ProcurementServiceProvider::class,
    ReturnsServiceProvider::class,
    NotificationsServiceProvider::class,
    AnalyticsServiceProvider::class,
    InvoicesServiceProvider::class,
];
