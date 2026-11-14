<?php

namespace App\Modules\Suppliers\Application;

use App\Modules\Catalog\Events\CatalogListingStale;
use App\Modules\Suppliers\Events\SourcingChanged;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;

/** Tells Pricing to reprice and Catalog to refresh listings after sourcing data changes. */
final class SourcingNotifier
{
    public function product(string $productId): void
    {
        event(new SourcingChanged($productId));
        event(new CatalogListingStale($productId));
    }

    public function supplier(string $supplierId): void
    {
        SupplierProduct::query()->withTrashed()->where('supplier_id', $supplierId)
            ->distinct()->pluck('product_id')->each(fn (string $id) => $this->product($id));
    }
}
