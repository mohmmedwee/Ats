<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Suppliers\Application\Connectors\Cj\CjDropshippingConnector;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Infrastructure\Models\ProductImport;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use App\Modules\Suppliers\Jobs\ImportCjProducts;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Browse the CJdropshipping catalog and import products as drafts (catalog.manage). */
final class CjController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'between:1,1000'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $supplier = $this->supplier();
        $page = (int) ($data['page'] ?? 1);

        try {
            $result = (new CjDropshippingConnector($supplier))->client()->get('product/listV2', [
                'keyWord' => $data['q'] ?? null,
                'startSellPrice' => $data['min_price'] ?? null,
                'endSellPrice' => $data['max_price'] ?? null,
                'page' => $page,
                'size' => 24,
            ]);
        } catch (ConnectorException $e) {
            throw new ApiException('SUPPLIER_UNAVAILABLE', 502, ['message' => $e->getMessage()]);
        }

        $items = [];
        foreach ((array) (is_array($result) ? ($result['content'] ?? []) : []) as $group) {
            foreach ((array) (is_array($group) ? ($group['productList'] ?? []) : []) as $p) {
                if (is_array($p) && isset($p['id'])) {
                    $items[] = [
                        'pid' => (string) $p['id'],
                        'title' => (string) ($p['nameEn'] ?? ''),
                        'sku' => $p['sku'] ?? null,
                        'image' => $p['bigImage'] ?? null,
                        'price_usd' => isset($p['sellPrice']) ? (string) $p['sellPrice'] : null,
                        'inventory' => isset($p['warehouseInventoryNum']) ? (int) $p['warehouseInventoryNum'] : null,
                        'listed' => isset($p['listedNum']) ? (int) $p['listedNum'] : null,
                    ];
                }
            }
        }
        $imported = SupplierProduct::query()->where('supplier_id', $supplier->id)->whereIn('external_product_id', array_column($items, 'pid'))->pluck('external_product_id')->all();
        foreach ($items as &$item) {
            $item['imported'] = in_array($item['pid'], $imported, true);
        }

        return ApiResponse::success($items, 200, ['pagination' => [
            'page' => (int) (is_array($result) ? ($result['pageNumber'] ?? $page) : $page),
            'total' => (int) (is_array($result) ? ($result['totalRecords'] ?? count($items)) : count($items)),
            'last_page' => (int) (is_array($result) ? ($result['totalPages'] ?? 1) : 1),
        ]]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pids' => ['required', 'array', 'min:1', 'max:50'],
            'pids.*' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'countries' => ['sometimes', 'array', 'min:1'],
            'countries.*' => [Rule::in((array) config('gulfcart.countries'))],
            'category_slug' => ['nullable', 'string', 'exists:categories,slug'],
        ]);
        $supplier = $this->supplier();

        $import = ProductImport::query()->create([
            'supplier_id' => $supplier->id, 'uploaded_by' => $request->user()?->getAuthIdentifier(),
            'file_path' => 'imports/cj-pending', 'status' => 'queued',
        ]);
        ImportCjProducts::dispatch($import->id, array_values(array_unique($data['pids'])), array_values($data['countries'] ?? (array) config('gulfcart.countries')), $data['category_slug'] ?? null);
        $this->audit->record('cj_import.queued', 'product_import', $import->id, null, ['pids' => count($data['pids'])]);

        return ApiResponse::success(['id' => $import->id, 'status' => 'queued'], 202);
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->where('connector_type', 'cj')->orderByDesc('is_active')->first()
            ?? throw new ApiException('NOT_FOUND', 404, ['message' => 'Run php artisan suppliers:cj-setup first.']);
    }
}
