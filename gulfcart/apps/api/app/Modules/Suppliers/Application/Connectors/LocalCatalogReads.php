<?php

namespace App\Modules\Suppliers\Application\Connectors;

use App\Modules\Suppliers\Domain\Connector\AvailabilityResult;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Domain\Connector\ExternalProduct;
use App\Modules\Suppliers\Domain\Connector\ExternalVariant;
use App\Modules\Suppliers\Domain\Connector\SupplierQuote;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use App\Shared\Money\Decimal;

/** Catalog reads served from our stored supplier data (manual and mock suppliers). */
trait LocalCatalogReads
{
    abstract protected function supplier(): Supplier;

    /** Hook for the mock to simulate price movements. */
    protected function adjustCost(string $cost): string
    {
        return $cost;
    }

    protected function adjustStock(string $externalVariantId, string $stock): string
    {
        return $stock;
    }

    public function getProduct(string $externalProductId): ExternalProduct
    {
        $sp = SupplierProduct::query()->with('mappings')->where('supplier_id', $this->supplier()->id)
            ->where('external_product_id', $externalProductId)->first()
            ?? throw new ConnectorException("Unknown external product {$externalProductId}.");

        return new ExternalProduct($externalProductId, null, $sp->mappings->map(fn ($m) => new ExternalVariant(
            $m->external_variant_id,
            $this->adjustCost((string) ($m->cost_price ?? $sp->cost_price)),
            $sp->cost_currency,
            $this->adjustStock($m->external_variant_id, (string) ($m->stock_status ?? $sp->stock_status)),
        ))->values()->all());
    }

    public function checkAvailability(string $externalProductId, string $externalVariantId): AvailabilityResult
    {
        $variant = $this->getProduct($externalProductId)->variant($externalVariantId);

        return $variant === null
            ? new AvailabilityResult('out_of_stock', null, null)
            : new AvailabilityResult($variant->stockStatus, $variant->cost, $variant->currency);
    }

    public function getQuote(array $lines, ?string $countryCode = null): SupplierQuote
    {
        $unit = [];
        $total = '0';
        $currency = $this->supplier()->base_currency;
        $shipping = '0';
        foreach ($lines as $line) {
            $availability = $this->checkAvailability($line->externalProductId, $line->externalVariantId);
            if (! $availability->isAvailable() || $availability->cost === null) {
                throw new ConnectorException("Variant {$line->externalVariantId} is not available.");
            }
            $unit[$line->externalVariantId] = $availability->cost;
            $currency = (string) $availability->currency;
            $total = Decimal::add($total, Decimal::mul($availability->cost, (string) $line->quantity));
            $shipping = Decimal::add($shipping, Decimal::mul((string) ($this->supplier()->metadata['shipping_cost_per_unit'] ?? '0'), (string) $line->quantity));
        }

        return new SupplierQuote($unit, $total, $shipping, $currency);
    }
}
