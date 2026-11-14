<?php

namespace App\Modules\Suppliers\Jobs;

use App\Modules\Suppliers\Application\ProductImportProcessor;
use App\Modules\Suppliers\Infrastructure\Models\ProductImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class ProcessProductImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $importId) {}

    public function handle(ProductImportProcessor $processor): void
    {
        $import = ProductImport::query()->find($this->importId);
        if ($import !== null && $import->status === 'queued') {
            $processor->process($import);
        }
    }
}
