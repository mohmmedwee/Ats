<?php

namespace App\Modules\Suppliers\Jobs;

use App\Modules\Suppliers\Application\Connectors\Cj\CjProductImporter;
use App\Modules\Suppliers\Infrastructure\Models\ProductImport;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class ImportCjProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  list<string>  $pids
     * @param  list<string>  $countries
     */
    public function __construct(
        public readonly string $importId,
        public readonly array $pids,
        public readonly array $countries,
        public readonly ?string $categorySlug,
    ) {}

    public function handle(CjProductImporter $importer): void
    {
        $import = ProductImport::query()->find($this->importId);
        if ($import === null || $import->status !== 'queued') {
            return;
        }

        try {
            $importer->import(Supplier::query()->findOrFail($import->supplier_id), $this->pids, $this->countries, $this->categorySlug, $import->uploaded_by, $import);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'errors' => [['row' => 0, 'errors' => [$e->getMessage()]]], 'completed_at' => now()]);
        }
    }
}
