<?php

namespace App\Modules\Checkout\Application;

use App\Modules\Cart\Domain\CartData;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Checkout\Infrastructure\Models\CustomerAddress;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Pricing\Contracts\PriceBook;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\Domain\ShippingOption;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Tax\Contracts\TaxRules;
use App\Modules\Tax\Domain\DutyLine;
use App\Shared\Exceptions\ApiException;
use App\Shared\I18n\Translations;
use App\Shared\Money\Decimal;

/**
 * Builds the full checkout quote from a cart. Prices are VAT inclusive; `tax` is the VAT
 * contained in the total. Customs is assessed per supplier shipment (DDP) against the
 * country's de minimis threshold and charged as its own line.
 */
final class QuoteBuilder
{
    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly PriceBook $prices,
        private readonly SourcingDirectory $sourcing,
        private readonly ConnectorResolver $connectors,
        private readonly TaxRules $tax,
        private readonly ShippingQuoter $shipping,
    ) {}

    /** @return list<ShippingOption> */
    public function shippingOptions(CartData $cart, CountryData $country): array
    {
        [$lines] = $this->lines($cart, $country, false);

        return $this->shipping->options($country->code, $this->subtotal($lines), $this->weight($lines));
    }

    /** @return array<string, mixed> internal quote (contains cost data; never send as-is to customers) */
    public function build(CartData $cart, CountryData $country, CustomerAddress $address, string $methodCode, bool $liveCheck): array
    {
        if ($address->country_id !== $country->id) {
            throw new ApiException('ADDRESS_COUNTRY_MISMATCH', 422);
        }
        [$lines, $groups] = $this->lines($cart, $country, $liveCheck);
        $subtotal = $this->subtotal($lines);

        $method = $this->shipping->option($country->code, $methodCode, $subtotal, $this->weight($lines))
            ?? throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['shipping_method' => ['Shipping method is not available.']]]);

        $shipments = [];
        $customsTotal = '0.00';
        foreach ($groups as $index => $group) {
            $duty = $this->tax->dutyForShipment($country->code, array_map(fn ($i) => new DutyLine($lines[$i]['hs_code'], $lines[$i]['customs_value']), $group['lines']), $country->currency);
            $customsTotal = Decimal::add($customsTotal, $duty->amount, 2);
            $shipments[] = [
                'number' => $index + 1,
                'supplier_id' => $group['supplier_id'],
                'line_indexes' => $group['lines'],
                'customs' => ['amount' => $duty->amount, 'customs_value' => $duty->customsValue, 'de_minimis_applied' => $duty->deMinimisApplied],
                'estimate' => [
                    'min_days' => max(1, $group['min_days'] + $method->extraMinDays),
                    'max_days' => max(1, $group['max_days'] + $method->extraMaxDays),
                ],
            ];
        }

        $shippingVatRate = $this->tax->shippingVatRate($country->code);
        $shippingVat = $this->contained($method->amount, $shippingVatRate);
        $tax = Decimal::add(Decimal::sum(array_column($lines, 'line_vat'), 2), $shippingVat, 2);
        $discount = '0.00';
        $total = Decimal::sub(Decimal::add(Decimal::add($subtotal, $method->amount, 2), $customsTotal, 2), $discount, 2);

        return [
            'country' => $country->code,
            'currency' => $country->currency,
            'address_id' => $address->id,
            'shipping_address' => $address->snapshot($country->code),
            'shipping_method' => ['id' => $method->methodId, 'code' => $method->code, 'name' => $method->name, 'amount' => $method->amount, 'free' => $method->free, 'vat' => $shippingVat],
            'lines' => $lines,
            'shipments' => $shipments,
            'totals' => [
                'subtotal' => $subtotal,
                'shipping' => $method->amount,
                'customs' => $customsTotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
            ],
            'prices_include_tax' => true,
        ];
    }

    /**
     * Customer-safe view of a quote: no supplier IDs, costs or exchange rates.
     *
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    public static function publicView(array $quote): array
    {
        $money = fn (string $amount) => ['amount' => $amount, 'currency' => $quote['currency']];

        return [
            'currency' => $quote['currency'],
            'country' => $quote['country'],
            'items' => array_map(fn ($l) => [
                'variant_id' => $l['variant_id'],
                'title' => Translations::pick($l['snapshot']['title'] ?? []),
                'sku' => $l['snapshot']['sku'] ?? null,
                'attributes' => (object) ($l['snapshot']['attributes'] ?? []),
                'image' => $l['snapshot']['image_url'] ?? null,
                'quantity' => $l['quantity'],
                'unit_price' => $money($l['unit_gross']),
                'line_total' => $money($l['line_total']),
                'shipment' => $l['shipment'],
            ], $quote['lines']),
            'shipments' => array_map(fn ($s) => [
                'number' => $s['number'],
                'item_count' => count($s['line_indexes']),
                'customs' => $money($s['customs']['amount']),
                'estimate' => $s['estimate'],
            ], $quote['shipments']),
            'shipping_method' => ['code' => $quote['shipping_method']['code'], 'name' => Translations::pick($quote['shipping_method']['name']), 'free' => $quote['shipping_method']['free']],
            'subtotal' => $quote['totals']['subtotal'],
            'shipping' => $quote['totals']['shipping'],
            'customs' => $quote['totals']['customs'],
            'discount' => $quote['totals']['discount'],
            'tax' => $quote['totals']['tax'],
            'total' => $quote['totals']['total'],
            'prices_include_tax' => true,
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array{supplier_id: string, lines: list<int>, min_days: int, max_days: int}>}
     */
    private function lines(CartData $cart, CountryData $country, bool $liveCheck): array
    {
        if ($cart->lines === []) {
            throw new ApiException('CART_EMPTY', 422);
        }
        $variantIds = array_map(fn ($l) => $l->variantId, $cart->lines);
        $variants = $this->catalog->variants($variantIds);
        $prices = $this->prices->pricesFor($variantIds, $country->code);

        $lines = [];
        $groups = [];
        foreach ($cart->lines as $cartLine) {
            $variant = $variants[$cartLine->variantId] ?? null;
            $price = $prices[$cartLine->variantId] ?? null;
            if ($variant === null || ! $variant->isPurchasable() || $price === null) {
                throw new ApiException('PRODUCT_NOT_AVAILABLE_IN_COUNTRY', 422, ['variant_id' => $cartLine->variantId]);
            }
            $source = $this->sourcing->source($price->supplierProductId, $variant->id);
            $eligible = array_filter($this->sourcing->sourcesFor($variant->id, $country->code), fn ($s) => $s->supplierProductId === $price->supplierProductId);
            if ($source === null || $eligible === []) {
                throw new ApiException('PRODUCT_OUT_OF_STOCK', 422, ['variant_id' => $variant->id]);
            }
            if ($liveCheck) {
                $this->liveCheck($source->supplierId, $source->externalProductId, $source->externalVariantId, $variant->id);
            }

            $qty = (string) $cartLine->quantity;
            $unitVat = Decimal::sub($price->gross, $price->net, 2);
            $fx = (string) ($price->calculation['exchange_rate'] ?? '1');
            $unitCost = (string) ($price->calculation['unit_cost'] ?? $source->unitCost);
            $unitShipping = (string) ($price->calculation['shipping_cost'] ?? $source->shippingCost);

            $index = count($lines);
            $groupKey = $source->supplierId;
            $groups[$groupKey] ??= ['supplier_id' => $source->supplierId, 'lines' => [], 'min_days' => $source->deliveryMinDays ?? 7, 'max_days' => $source->deliveryMaxDays ?? 14];
            $groups[$groupKey]['lines'][] = $index;

            $lines[] = [
                'variant_id' => $variant->id,
                'product_id' => $variant->productId,
                'quantity' => $cartLine->quantity,
                'unit_gross' => $price->gross,
                'unit_net' => $price->net,
                'unit_vat' => $unitVat,
                'vat_rate' => $price->vatRate,
                'line_total' => Decimal::mul($price->gross, $qty, 2),
                'line_vat' => Decimal::mul($unitVat, $qty, 2),
                'supplier_id' => $source->supplierId,
                'supplier_product_id' => $source->supplierProductId,
                'external_product_id' => $source->externalProductId,
                'external_variant_id' => $source->externalVariantId,
                'unit_cost' => $unitCost,
                'unit_shipping_cost' => $unitShipping,
                'cost_currency' => $source->costCurrency,
                'exchange_rate' => $fx,
                'exchange_rate_id' => $price->exchangeRateId,
                'variable_cost' => $price->variableCost,
                'customs_value' => Decimal::round(Decimal::mul(Decimal::mul(Decimal::add($unitCost, $unitShipping, 4), $fx, 8), $qty, 8), 2),
                'hs_code' => $variant->hsCode,
                'weight_grams' => $variant->weightGrams,
                'snapshot' => $variant->snapshot(),
                'pricing' => $price->calculation,
                'shipment' => null,
            ];
        }

        $groups = array_values($groups);
        foreach ($groups as $n => $group) {
            foreach ($group['lines'] as $i) {
                $lines[$i]['shipment'] = $n + 1;
            }
        }

        return [$lines, $groups];
    }

    private function liveCheck(string $supplierId, string $externalProductId, string $externalVariantId, string $variantId): void
    {
        try {
            $availability = $this->connectors->forSupplier($supplierId)->checkAvailability($externalProductId, $externalVariantId);
        } catch (ConnectorException) {
            return; // Supplier unreachable: rely on synced stock; procurement revalidates before buying.
        }
        if (! $availability->isAvailable()) {
            throw new ApiException('PRODUCT_OUT_OF_STOCK', 422, ['variant_id' => $variantId]);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function subtotal(array $lines): string
    {
        return Decimal::sum(array_column($lines, 'line_total'), 2);
    }

    /** @param list<array<string, mixed>> $lines */
    private function weight(array $lines): int
    {
        return array_sum(array_map(fn ($l) => ((int) ($l['weight_grams'] ?? 500)) * $l['quantity'], $lines));
    }

    private function contained(string $gross, string $ratePercent): string
    {
        return Decimal::cmp($ratePercent, '0') === 0 ? '0.00'
            : Decimal::round(Decimal::div(Decimal::mul($gross, $ratePercent, 8), Decimal::add('100', $ratePercent, 8), 8), 2);
    }
}
