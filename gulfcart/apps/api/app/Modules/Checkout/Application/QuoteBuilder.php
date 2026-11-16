<?php

namespace App\Modules\Checkout\Application;

use App\Modules\Cart\Domain\CartData;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Checkout\Infrastructure\Models\CustomerAddress;
use App\Modules\Countries\Domain\CountryData;
use App\Modules\Pricing\Contracts\PriceBook;
use App\Modules\Promotions\Contracts\Coupons;
use App\Modules\Promotions\Contracts\Offers;
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
        private readonly Coupons $coupons,
        private readonly Offers $offers,
    ) {}

    /** @return list<ShippingOption> */
    public function shippingOptions(CartData $cart, CountryData $country): array
    {
        [$lines] = $this->lines($cart, $country, false);

        return $this->shipping->options($country->code, $this->subtotal($lines), $this->weight($lines));
    }

    /** @return array<string, mixed> internal quote (contains cost data; never send as-is to customers) */
    public function build(CartData $cart, CountryData $country, CustomerAddress $address, string $methodCode, bool $liveCheck, ?string $couponCode = null, ?string $userId = null, ?string $paymentMethod = null): array
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

        // Automatic offer first (buy X get Y), on the exact units it covers; a coupon then applies to what is left.
        $offer = $this->offers->best(array_map(fn ($l) => ['product_id' => $l['product_id'], 'category_ids' => $l['category_ids'], 'unit_gross' => $l['unit_gross'], 'quantity' => $l['quantity']], $lines), $country->code);
        $offerDiscount = '0.00';
        if ($offer !== null) {
            foreach ($offer['line_discounts'] as $i => $amount) {
                $lines[$i]['line_discount'] = Decimal::add((string) $lines[$i]['line_discount'], $amount, 2);
                $lines[$i]['line_vat'] = $this->contained(Decimal::sub((string) $lines[$i]['line_total'], (string) $lines[$i]['line_discount'], 2), (string) $lines[$i]['vat_rate']);
            }
            $offerDiscount = $offer['discount'];
        }

        $coupon = null;
        $discount = $offerDiscount;
        $shippingAmount = $method->amount;
        if ($couponCode !== null && trim($couponCode) !== '' && $userId !== null) {
            $coupon = $this->coupons->evaluate($couponCode, $userId, $country->code, Decimal::sub($subtotal, $offerDiscount, 2));
            $discount = Decimal::add($offerDiscount, $coupon->discount, 2);
            $lines = $this->allocateDiscount($lines, $coupon->discount);
            if ($coupon->freeShipping) {
                $shippingAmount = '0.00';
            }
        }

        // Cash on delivery: a handling fee charged like delivery (same VAT treatment), within the configured limits.
        $codFee = '0.00';
        if ($paymentMethod === 'cod') {
            $codFee = self::codFee($country->code);
            $shippingAmount = Decimal::add($shippingAmount, $codFee, 2);
        }

        $shippingVatRate = $this->tax->shippingVatRate($country->code);
        $shippingVat = $this->contained($shippingAmount, $shippingVatRate);
        $tax = Decimal::add(Decimal::sum(array_column($lines, 'line_vat'), 2), $shippingVat, 2);
        $total = Decimal::sub(Decimal::add(Decimal::add($subtotal, $shippingAmount, 2), $customsTotal, 2), $discount, 2);
        if ($paymentMethod === 'cod') {
            self::assertCodAllowed($country->code, $total);
        }

        return [
            'country' => $country->code,
            'currency' => $country->currency,
            'address_id' => $address->id,
            'shipping_address' => $address->snapshot($country->code),
            'shipping_method' => ['id' => $method->methodId, 'code' => $method->code, 'name' => $method->name, 'amount' => $shippingAmount, 'free' => $method->free || Decimal::isZero($shippingAmount), 'vat' => $shippingVat],
            'coupon' => $coupon === null ? null : ['code' => $coupon->code, 'type' => $coupon->type, 'discount' => $coupon->discount, 'free_shipping' => $coupon->freeShipping],
            'offer' => $offer === null ? null : ['id' => $offer['offer_id'], 'name' => $offer['name'], 'discount' => $offerDiscount],
            'lines' => $lines,
            'shipments' => $shipments,
            'totals' => [
                'subtotal' => $subtotal,
                'shipping' => $shippingAmount,
                'customs' => $customsTotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
            ],
            'payment_method' => $paymentMethod,
            'cod_fee' => $codFee,
            'prices_include_tax' => true,
        ];
    }

    public static function codFee(string $countryCode): string
    {
        return Decimal::round((string) config("gulfcart.cod.fee.{$countryCode}", '0'), 2);
    }

    /** Cash on delivery must be switched on, offered in the country and the order within the limit. */
    public static function assertCodAllowed(string $countryCode, string $total): void
    {
        $countries = array_filter(array_map('trim', explode(',', (string) config('gulfcart.cod.countries', ''))));
        if (! config('gulfcart.cod.enabled') || ! in_array($countryCode, $countries, true)) {
            throw new ApiException('COD_NOT_AVAILABLE', 422, ['reason' => 'not_offered'], (string) __('errors.cod_not_offered'));
        }
        $max = config("gulfcart.cod.max_amount.{$countryCode}");
        if ($max !== null && $max !== '' && Decimal::cmp($total, (string) $max) > 0) {
            throw new ApiException('COD_NOT_AVAILABLE', 422, ['reason' => 'max_amount', 'max_amount' => (string) $max], (string) __('errors.cod_max_amount', ['amount' => $max]));
        }
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
            'coupon' => isset($quote['coupon']) ? ['code' => $quote['coupon']['code'], 'free_shipping' => $quote['coupon']['free_shipping']] : null,
            'tax' => $quote['totals']['tax'],
            'total' => $quote['totals']['total'],
            'payment_method' => $quote['payment_method'] ?? null,
            'cod_fee' => $quote['cod_fee'] ?? '0.00',
            'offer' => isset($quote['offer']) ? ['name' => Translations::pick($quote['offer']['name']), 'discount' => $quote['offer']['discount']] : null,
            'prices_include_tax' => true,
        ];
    }

    /**
     * Spreads a VAT-inclusive discount over the lines in proportion to their totals (the last line takes
     * the rounding remainder) and recomputes each discounted line's contained VAT. Invoices and refunds
     * use the same per-line figures, so VAT stays consistent everywhere.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function allocateDiscount(array $lines, string $discount): array
    {
        // Spread over what is left after any offer discount already on the lines.
        $subtotal = Decimal::sum(array_map(fn ($l) => Decimal::sub((string) $l['line_total'], (string) $l['line_discount'], 2), $lines), 2);
        if (Decimal::isZero($discount) || Decimal::isZero($subtotal)) {
            return $lines;
        }
        $left = $discount;
        $last = count($lines) - 1;
        foreach ($lines as $i => $line) {
            $share = $i === $last
                ? $left
                : Decimal::round(Decimal::div(Decimal::mul($discount, Decimal::sub((string) $line['line_total'], (string) $line['line_discount'], 2), 8), $subtotal, 8), 2);
            $room = Decimal::sub((string) $line['line_total'], (string) $line['line_discount'], 2);
            if (Decimal::cmp($share, $room) > 0) {
                $share = $room;
            }
            $left = Decimal::sub($left, $share, 2);
            $lines[$i]['line_discount'] = Decimal::add((string) $line['line_discount'], $share, 2);
            $lines[$i]['line_vat'] = $this->contained(Decimal::sub((string) $line['line_total'], (string) $lines[$i]['line_discount'], 2), (string) $line['vat_rate']);
        }

        return $lines;
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
                'category_ids' => $this->catalog->categoryLineage($variant->categoryId),
                'quantity' => $cartLine->quantity,
                'unit_gross' => $price->gross,
                'unit_net' => $price->net,
                'unit_vat' => $unitVat,
                'vat_rate' => $price->vatRate,
                'line_total' => Decimal::mul($price->gross, $qty, 2),
                'line_vat' => Decimal::mul($unitVat, $qty, 2),
                'line_discount' => '0.00',
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
