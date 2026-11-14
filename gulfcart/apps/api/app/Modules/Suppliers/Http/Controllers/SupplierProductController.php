<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Suppliers\Application\SourcingNotifier;
use App\Modules\Suppliers\Application\SupplierProductSync;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SupplierProductController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SourcingNotifier $notifier,
        private readonly CatalogReader $catalog,
    ) {}

    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        $page = $supplier->products()->with('mappings')->latest()->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paged($page, $page->getCollection()->map(fn ($sp) => $this->present($sp))->all());
    }

    public function store(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'external_product_id' => ['required', 'string', 'max:255', Rule::unique('supplier_products')->where('supplier_id', $supplier->id)],
            ...$this->commonRules(true),
        ]);
        $sp = $supplier->products()->create($data + ['is_active' => true]);
        $this->audit->record('supplier_product.created', 'supplier_product', $sp->id, null, $data);
        $this->notifier->product($sp->product_id);

        return ApiResponse::success($this->present($sp->load('mappings')), 201);
    }

    public function update(Request $request, SupplierProduct $supplierProduct): JsonResponse
    {
        $data = $request->validate($this->commonRules(false) + ['is_active' => ['sometimes', 'boolean']]);
        $old = $supplierProduct->only(array_keys($data));
        $supplierProduct->update($data);
        $this->audit->record('supplier_product.updated', 'supplier_product', $supplierProduct->id, $old, $data);
        $this->notifier->product($supplierProduct->product_id);

        return ApiResponse::success($this->present($supplierProduct->load('mappings')));
    }

    public function mappings(Request $request, SupplierProduct $supplierProduct): JsonResponse
    {
        $rows = $request->validate([
            'mappings' => ['present', 'array'],
            'mappings.*.variant_id' => ['required', 'uuid', 'distinct'],
            'mappings.*.external_variant_id' => ['required', 'string', 'max:255', 'distinct'],
            'mappings.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'mappings.*.stock_status' => ['nullable', 'in:in_stock,low_stock,out_of_stock,unknown'],
        ])['mappings'];

        $variantIds = $this->catalog->variantIdsOf($supplierProduct->product_id);
        foreach ($rows as $i => $row) {
            if (! in_array($row['variant_id'], $variantIds, true)) {
                throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ["mappings.{$i}.variant_id" => ['Variant is not an active variant of this product.']]]);
            }
        }

        DB::transaction(function () use ($rows, $supplierProduct): void {
            $supplierProduct->mappings()->whereNotIn('variant_id', array_column($rows, 'variant_id'))->delete();
            foreach ($rows as $row) {
                $supplierProduct->mappings()->updateOrCreate(['variant_id' => $row['variant_id']], [
                    'external_variant_id' => $row['external_variant_id'],
                    'cost_price' => $row['cost_price'] ?? null,
                    'stock_status' => $row['stock_status'] ?? null,
                ]);
            }
        });
        $this->audit->record('supplier_product.mappings_updated', 'supplier_product', $supplierProduct->id, null, ['mappings' => $rows]);
        $this->notifier->product($supplierProduct->product_id);

        return ApiResponse::success($this->present($supplierProduct->load('mappings')));
    }

    public function sync(SupplierProduct $supplierProduct, SupplierProductSync $sync): JsonResponse
    {
        try {
            $result = $sync->sync($supplierProduct);
        } catch (ConnectorException $e) {
            throw new ApiException('SUPPLIER_CONNECTOR_ERROR', 502, [], $e->getMessage());
        }

        return ApiResponse::success($this->present($supplierProduct->refresh()->load('mappings')) + ['changed' => $result['changed']]);
    }

    /** @return array<string, mixed> */
    private function commonRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'external_sku' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:2000'],
            'cost_price' => [$req, 'numeric', 'min:0', 'max:9999999999'],
            'cost_currency' => [$req, 'string', 'regex:/^[A-Z]{3}$/'],
            'stock_status' => [$req, 'in:in_stock,low_stock,out_of_stock,unknown'],
            'priority' => ['sometimes', 'integer', 'between:1,1000'],
            'supplier_data' => ['sometimes', 'array'],
            'supplier_data.shipping_cost' => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(SupplierProduct $sp): array
    {
        return [
            'id' => $sp->id, 'supplier_id' => $sp->supplier_id, 'product_id' => $sp->product_id,
            'external_product_id' => $sp->external_product_id, 'external_sku' => $sp->external_sku,
            'source_url' => $sp->source_url, 'cost_price' => $sp->cost_price, 'cost_currency' => $sp->cost_currency,
            'stock_status' => $sp->stock_status, 'priority' => $sp->priority, 'is_active' => $sp->is_active,
            'supplier_data' => (object) $sp->supplier_data, 'last_synced_at' => $sp->last_synced_at?->toIso8601String(),
            'mappings' => $sp->mappings->map(fn ($m) => [
                'variant_id' => $m->variant_id, 'external_variant_id' => $m->external_variant_id,
                'cost_price' => $m->cost_price, 'stock_status' => $m->stock_status,
            ])->values(),
        ];
    }
}
