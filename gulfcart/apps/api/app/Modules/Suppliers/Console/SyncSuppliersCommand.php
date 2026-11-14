<?php

namespace App\Modules\Suppliers\Console;

use App\Modules\Suppliers\Application\SupplierProductSync;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use Illuminate\Console\Command;
use Throwable;

final class SyncSuppliersCommand extends Command
{
    protected $signature = 'suppliers:sync {--supplier= : Supplier code}';

    protected $description = 'Sync cost and stock from suppliers whose connectors support catalog sync';

    public function handle(ConnectorResolver $connectors, SupplierProductSync $sync): int
    {
        $suppliers = Supplier::query()->where('is_active', true)
            ->when($this->option('supplier'), fn ($q, $code) => $q->where('code', $code))->get();

        foreach ($suppliers as $supplier) {
            if (! $connectors->forSupplier($supplier->id)->capabilities()->catalogSync) {
                continue;
            }
            $changed = 0;
            SupplierProduct::query()->where('supplier_id', $supplier->id)->where('is_active', true)
                ->each(function (SupplierProduct $sp) use ($sync, &$changed) {
                    try {
                        $changed += $sync->sync($sp)['changed'];
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            $this->info("{$supplier->code}: {$changed} variant(s) changed");
        }

        return self::SUCCESS;
    }
}
