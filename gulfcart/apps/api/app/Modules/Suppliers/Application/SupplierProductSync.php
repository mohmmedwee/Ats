<?php

namespace App\Modules\Suppliers\Application;

use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use Illuminate\Support\Facades\DB;

/** Pulls cost and stock for one supplier product from its connector. */
final class SupplierProductSync
{
    public function __construct(private readonly ConnectorResolver $connectors, private readonly SourcingNotifier $notifier) {}

    /** @return array{changed: int} */
    public function sync(SupplierProduct $sp): array
    {
        $connector = $this->connectors->forSupplier($sp->supplier_id);
        $remote = $connector->getProduct($sp->external_product_id);
        $changed = 0;

        DB::transaction(function () use ($sp, $remote, &$changed): void {
            foreach ($sp->mappings()->get() as $mapping) {
                $variant = $remote->variant($mapping->external_variant_id);
                $update = $variant === null
                    ? ['stock_status' => 'out_of_stock']
                    : ['cost_price' => $variant->cost, 'stock_status' => $variant->stockStatus];
                $mapping->fill($update);
                if ($mapping->isDirty()) {
                    $mapping->save();
                    $changed++;
                }
            }
            $sp->forceFill(['last_synced_at' => now()])->save();
        });

        if ($changed > 0) {
            $this->notifier->product($sp->product_id);
        }

        return ['changed' => $changed];
    }
}
