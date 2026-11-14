<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Suppliers\Infrastructure\Models\ProductImport;
use App\Modules\Suppliers\Jobs\ProcessProductImport;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $import = ProductImport::query()->create([
            'supplier_id' => $data['supplier_id'],
            'uploaded_by' => $request->user()?->getAuthIdentifier(),
            'file_path' => $request->file('file')->store('imports', 'local'),
            'status' => 'queued',
        ]);
        ProcessProductImport::dispatch($import->id);
        $this->audit->record('product_import.queued', 'product_import', $import->id, null, ['supplier_id' => $data['supplier_id']]);

        return ApiResponse::success($this->present($import->refresh()), 202);
    }

    public function index(Request $request): JsonResponse
    {
        $page = ProductImport::query()->latest()->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paged($page, $page->getCollection()->map(fn ($i) => $this->present($i))->all());
    }

    public function show(ProductImport $import): JsonResponse
    {
        return ApiResponse::success($this->present($import));
    }

    /** @return array<string, mixed> */
    private function present(ProductImport $i): array
    {
        return $i->only(['id', 'supplier_id', 'status', 'total_rows', 'created_count', 'updated_count', 'error_count', 'errors'])
            + ['source' => str_starts_with($i->file_path, 'imports/cj-') ? 'cj' : 'csv', 'created_at' => $i->created_at?->toIso8601String(), 'completed_at' => $i->completed_at?->toIso8601String()];
    }
}
