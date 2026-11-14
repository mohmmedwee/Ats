<?php

namespace App\Modules\Suppliers\Application;

use App\Modules\Catalog\Contracts\CatalogStock;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use App\Modules\Suppliers\Domain\SourceData;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierAuthorization;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use App\Modules\Suppliers\Infrastructure\Models\SupplierVariantMapping;

final class EloquentSourcingDirectory implements CatalogStock, SourcingDirectory
{
    public function sourcesFor(string $variantId, string $countryCode): array
    {
        return $this->eligible([$variantId], strtoupper($countryCode))[$variantId] ?? [];
    }

    public function productIdsWithSources(): array
    {
        return SupplierProduct::query()->distinct()->pluck('product_id')->all();
    }

    public function source(string $supplierProductId, string $variantId): ?SourceData
    {
        $mapping = SupplierVariantMapping::query()
            ->with(['supplierProduct' => fn ($q) => $q->withTrashed()->with(['supplier' => fn ($s) => $s->withTrashed()])])
            ->where('supplier_product_id', $supplierProductId)
            ->where('variant_id', $variantId)
            ->first();

        if ($mapping === null) {
            return null;
        }
        $auth = $mapping->supplierProduct->supplier->currentAuthorization();

        return $this->toSource($mapping, $auth);
    }

    public function variantAvailability(array $variantIds, string $countryCode): array
    {
        $out = [];
        foreach ($this->eligible($variantIds, strtoupper($countryCode)) as $variantId => $sources) {
            $states = array_map(fn (SourceData $s) => $s->stockStatus, $sources);
            $out[$variantId] = in_array('in_stock', $states, true) || in_array('unknown', $states, true)
                ? 'in_stock'
                : (in_array('low_stock', $states, true) ? 'low_stock' : 'out_of_stock');
        }

        return $out;
    }

    /**
     * @param  list<string>  $variantIds
     * @return array<string, list<SourceData>>
     */
    public function eligible(array $variantIds, string $countryCode): array
    {
        if ($variantIds === []) {
            return [];
        }

        $mappings = SupplierVariantMapping::query()
            ->with(['supplierProduct.supplier'])
            ->whereIn('variant_id', array_values(array_unique($variantIds)))
            ->whereHas('supplierProduct', fn ($q) => $q->where('is_active', true)
                ->whereHas('supplier', fn ($s) => $s->where('is_active', true)->where('commercial_authorization_status', 'approved')))
            ->get();

        $authorizations = $this->authorizationsFor($mappings->map(fn ($m) => $m->supplierProduct->supplier_id)->unique()->values()->all());

        $out = [];
        foreach ($mappings as $mapping) {
            $auth = $authorizations[$mapping->supplierProduct->supplier_id] ?? null;
            if ($auth === null || ! in_array($countryCode, $auth->countryList(), true)) {
                continue;
            }
            $source = $this->toSource($mapping, $auth);
            if ($source->stockStatus === 'out_of_stock') {
                continue;
            }
            $out[$mapping->variant_id][] = $source;
        }

        foreach ($out as &$sources) {
            usort($sources, fn (SourceData $a, SourceData $b) => [$a->priority, (float) $a->unitCost] <=> [$b->priority, (float) $b->unitCost]);
        }

        return $out;
    }

    /**
     * @param  list<string>  $supplierIds
     * @return array<string, SupplierAuthorization>
     */
    private function authorizationsFor(array $supplierIds): array
    {
        $today = now()->toDateString();
        $rows = SupplierAuthorization::query()
            ->whereIn('supplier_id', $supplierIds)
            ->where('allows_resale', true)
            ->where('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->orderByDesc('valid_from')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->supplier_id] ??= $row;
        }

        return $out;
    }

    private function toSource(SupplierVariantMapping $mapping, ?SupplierAuthorization $auth): SourceData
    {
        $sp = $mapping->supplierProduct;
        /** @var Supplier $supplier */
        $supplier = $sp->supplier;
        $meta = $supplier->metadata;

        return new SourceData(
            supplierId: $supplier->id,
            supplierCode: $supplier->code,
            connectorType: $supplier->connector_type,
            supplierProductId: $sp->id,
            variantId: $mapping->variant_id,
            externalProductId: $sp->external_product_id,
            externalVariantId: $mapping->external_variant_id,
            unitCost: (string) $mapping->effectiveCost(),
            costCurrency: $sp->cost_currency,
            shippingCost: (string) ($sp->supplier_data['shipping_cost'] ?? $meta['shipping_cost_per_unit'] ?? '0'),
            stockStatus: $mapping->effectiveStock(),
            priority: $sp->priority,
            deliveryMinDays: isset($meta['delivery_min_days']) ? (int) $meta['delivery_min_days'] : null,
            deliveryMaxDays: isset($meta['delivery_max_days']) ? (int) $meta['delivery_max_days'] : null,
            allowsApiPurchasing: (bool) ($auth?->allows_api_purchasing ?? false),
        );
    }
}
