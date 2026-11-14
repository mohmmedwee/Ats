<?php

namespace App\Modules\Suppliers\Application\Connectors\Cj;

use App\Modules\Countries\Contracts\AddressRules;
use App\Modules\Suppliers\Contracts\SupplierConnector;
use App\Modules\Suppliers\Domain\Connector\AvailabilityResult;
use App\Modules\Suppliers\Domain\Connector\CancellationResult;
use App\Modules\Suppliers\Domain\Connector\ConnectorCapabilities;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Domain\Connector\ExternalProduct;
use App\Modules\Suppliers\Domain\Connector\ExternalVariant;
use App\Modules\Suppliers\Domain\Connector\PurchaseLine;
use App\Modules\Suppliers\Domain\Connector\PurchaseRequest;
use App\Modules\Suppliers\Domain\Connector\PurchaseResult;
use App\Modules\Suppliers\Domain\Connector\SupplierOrderStatus;
use App\Modules\Suppliers\Domain\Connector\SupplierQuote;
use App\Modules\Suppliers\Domain\Connector\TrackingInfo;
use App\Modules\Suppliers\Domain\Connector\TransientConnectorException;
use App\Modules\Suppliers\Domain\Connector\UnknownOutcomeException;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Shared\Money\Decimal;
use Illuminate\Support\Facades\Log;

/**
 * CJdropshipping (https://cjdropshipping.com): a dropshipping supplier with a public API that
 * ships to the UAE and Saudi Arabia. Prices are in USD. External IDs: product = CJ "pid",
 * variant = CJ "vid". Our idempotency key is sent as CJ's "orderNumber".
 *
 * Purchase flow: createOrderV2 -> (optional) confirmOrder -> payBalance from the CJ wallet.
 * If the wallet payment fails, the order is handed to an operator (MANUAL) with the CJ order ID.
 *
 * Supplier settings (suppliers.metadata.cj, all optional):
 *   from_country   ship-from country code (default config gulfcart.cj.from_country, "CN")
 *   logistics      preferred CJ logistic names in order, e.g. ["CJPacket Ordinary"]
 *   auto_pay       pay from the CJ balance after creating the order (default config)
 */
final class CjDropshippingConnector implements SupplierConnector
{
    private const STATUS = [
        'CREATED' => 'pending', 'IN_CART' => 'pending', 'UNPAID' => 'pending', 'UNCONFIRMED' => 'pending',
        'UNSHIPPED' => 'processing', 'PROCESSING' => 'processing', 'PAID' => 'processing',
        'SHIPPED' => 'shipped', 'DELIVERED' => 'delivered', 'COMPLETED' => 'delivered',
        'CANCELLED' => 'cancelled', 'TRASH' => 'cancelled', 'CLOSED' => 'cancelled',
    ];

    private readonly CjClient $client;

    /** @var array<string, ExternalProduct> per-instance memo (one procurement attempt) */
    private array $products = [];

    public function __construct(private readonly Supplier $supplier, ?CjClient $client = null)
    {
        $this->client = $client ?? new CjClient(
            $this->apiKey(),
            (string) ($supplier->api_base_url ?: config('gulfcart.cj.base_url')),
            (int) config('gulfcart.cj.timeout', 20),
        );
    }

    public function capabilities(): ConnectorCapabilities
    {
        // CJ does not document orderNumber deduplication, so unknown outcomes are reconciled
        // by looking the order up, and never resubmitted blindly.
        return new ConnectorCapabilities(
            automatedPurchasing: true,
            idempotentOrders: false,
            cancellation: true,
            tracking: true,
            catalogSync: true,
        );
    }

    public function client(): CjClient
    {
        return $this->client;
    }

    public function getProduct(string $externalProductId): ExternalProduct
    {
        if (isset($this->products[$externalProductId])) {
            return $this->products[$externalProductId];
        }

        $data = $this->client->get('product/query', ['pid' => $externalProductId]);
        if (! is_array($data)) {
            throw new ConnectorException("CJ product {$externalProductId} not found.");
        }
        $stock = $this->inventory($externalProductId);

        $variants = [];
        foreach ((array) ($data['variants'] ?? []) as $v) {
            if (! is_array($v) || ! isset($v['vid'])) {
                continue;
            }
            $vid = (string) $v['vid'];
            $variants[] = new ExternalVariant(
                $vid,
                self::price($v['variantSellPrice'] ?? $data['sellPrice'] ?? null),
                'USD',
                self::stockStatus($stock[$vid] ?? null),
            );
        }

        return $this->products[$externalProductId] = new ExternalProduct(
            $externalProductId,
            is_string($data['productNameEn'] ?? null) ? $data['productNameEn'] : null,
            $variants,
        );
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
        if ($lines === []) {
            throw new ConnectorException('Nothing to quote.');
        }
        $unit = [];
        $items = '0';
        foreach ($lines as $line) {
            $availability = $this->checkAvailability($line->externalProductId, $line->externalVariantId);
            if (! $availability->isAvailable() || $availability->cost === null) {
                throw new ConnectorException("CJ variant {$line->externalVariantId} is not available.");
            }
            $unit[$line->externalVariantId] = $availability->cost;
            $items = Decimal::add($items, Decimal::mul($availability->cost, (string) $line->quantity));
        }

        return new SupplierQuote($unit, $items, $this->freight($lines, $countryCode ?? (string) config('gulfcart.cj.quote_country', 'SA'))['price'], 'USD');
    }

    /**
     * Shipping options for the given lines to a destination country.
     *
     * @param  list<PurchaseLine>  $lines
     * @return array{name: string, price: string, aging: string|null}
     */
    public function freight(array $lines, string $countryCode): array
    {
        $data = $this->client->post('logistic/freightCalculate', [
            'startCountryCode' => $this->fromCountry(),
            'endCountryCode' => strtoupper($countryCode),
            'products' => array_map(fn ($l) => ['vid' => $l->externalVariantId, 'quantity' => $l->quantity], $lines),
        ], isWrite: false);

        $options = [];
        foreach ((array) $data as $o) {
            if (is_array($o) && is_string($o['logisticName'] ?? null) && $o['logisticName'] !== '' && isset($o['logisticPrice'])) {
                $options[$o['logisticName']] = ['name' => $o['logisticName'], 'price' => self::price($o['logisticPrice']), 'aging' => isset($o['logisticAging']) ? (string) $o['logisticAging'] : null];
            }
        }
        if ($options === []) {
            throw new ConnectorException("CJ has no shipping method to {$countryCode} for these items.");
        }
        foreach ($this->preferredLogistics() as $name) {
            if (isset($options[$name])) {
                return $options[$name];
            }
        }
        uasort($options, fn ($a, $b) => Decimal::cmp($a['price'], $b['price']));

        return reset($options);
    }

    public function createOrder(PurchaseRequest $request, string $idempotencyKey): PurchaseResult
    {
        $address = $request->shippingAddress;
        $country = strtoupper((string) ($address['country'] ?? ''));
        // Choosing the logistic happens before anything is sent: transient failures are safe
        // to retry, a permanent one (no shipping method) is a rejection, not an unknown outcome.
        try {
            $logistic = $this->freight($request->lines, $country);
        } catch (TransientConnectorException $e) {
            throw $e;
        } catch (ConnectorException $e) {
            return new PurchaseResult(PurchaseResult::REJECTED, null, null, null, ['error' => $e->getMessage()], $e->getMessage());
        }

        $payload = [
            'orderNumber' => $idempotencyKey,
            'shippingCountryCode' => $country,
            'shippingProvince' => $this->regionName($country, (string) ($address['state'] ?? '')),
            'shippingCity' => (string) ($address['city'] ?? ''),
            'shippingAddress' => trim(implode(', ', array_filter([$address['address_line_1'] ?? $address['line1'] ?? null, $address['district'] ?? null]))),
            'shippingAddress2' => (string) ($address['address_line_2'] ?? $address['line2'] ?? ''),
            'shippingZip' => (string) (($address['postal_code'] ?? null) ?: '00000'),
            'shippingCustomerName' => (string) ($address['full_name'] ?? $address['name'] ?? ''),
            'shippingPhone' => (string) ($address['phone'] ?? ''),
            'remark' => $request->reference,
            'logisticName' => $logistic['name'],
            'fromCountryCode' => $this->fromCountry(),
            'products' => array_map(fn ($l) => ['vid' => $l->externalVariantId, 'quantity' => $l->quantity], $request->lines),
        ];

        try {
            $data = $this->client->post('shopping/order/createOrderV2', $payload);
        } catch (CjApiException $e) {
            return new PurchaseResult(PurchaseResult::REJECTED, null, null, null, ['code' => $e->getCode(), 'error' => $e->getMessage()], $e->getMessage());
        }

        $orderId = is_array($data) ? (string) ($data['orderId'] ?? '') : '';
        if ($orderId === '') {
            // CJ said OK but returned no order: treat as unknown and reconcile by orderNumber.
            throw new UnknownOutcomeException('CJ accepted the order but returned no orderId.');
        }
        $total = self::orderTotal((array) $data);
        $raw = ['external_order_id' => $orderId, 'logistic' => $logistic['name'], 'status' => $data['orderStatus'] ?? null, 'total' => $total];

        if (! $this->autoPay()) {
            return new PurchaseResult(PurchaseResult::MANUAL_REQUIRED, $orderId, $total, 'USD', $raw, "CJ order {$orderId} created. Pay it in the CJ dashboard, then record it as purchased.");
        }

        try {
            if ((bool) config('gulfcart.cj.confirm_before_pay', true)) {
                $this->client->post('shopping/order/confirmOrder', ['orderId' => $orderId]);
            }
            $this->client->post('shopping/pay/payBalance', ['orderId' => $orderId]);
        } catch (ConnectorException $e) {
            Log::warning('suppliers.cj.payment_failed', ['reference' => $request->reference, 'cj_order' => $orderId, 'error' => $e->getMessage()]);

            return new PurchaseResult(PurchaseResult::MANUAL_REQUIRED, $orderId, $total, 'USD', $raw + ['payment_error' => $e->getMessage()],
                "CJ order {$orderId} was created but paying it from the CJ balance failed ({$e->getMessage()}). Top up the CJ balance and pay it in the CJ dashboard, then record it as purchased.");
        }

        return new PurchaseResult(PurchaseResult::ACCEPTED, $orderId, $total, 'USD', $raw + ['paid' => true]);
    }

    public function findOrderByReference(string $idempotencyKey): ?SupplierOrderStatus
    {
        try {
            $data = $this->client->get('shopping/order/getOrderDetail', ['orderId' => $idempotencyKey]);
            if (is_array($data) && ($data['orderNum'] ?? $data['orderNumber'] ?? null) === $idempotencyKey) {
                return $this->status($data);
            }
        } catch (CjApiException $e) {
            if (! $e->isNotFound()) {
                throw $e;
            }
        }

        // Fallback: scan the most recent orders for our orderNumber.
        $page = $this->client->get('shopping/order/list', ['pageNum' => 1, 'pageSize' => 100]);
        foreach ((array) (is_array($page) ? ($page['list'] ?? $page['content'] ?? []) : []) as $order) {
            if (is_array($order) && ($order['orderNum'] ?? $order['orderNumber'] ?? null) === $idempotencyKey) {
                return $this->status($order);
            }
        }

        return null;
    }

    public function getOrderStatus(string $externalOrderId): SupplierOrderStatus
    {
        return $this->status($this->detail($externalOrderId));
    }

    public function getTracking(string $externalOrderId): TrackingInfo
    {
        $order = $this->detail($externalOrderId);
        $number = is_string($order['trackNumber'] ?? null) && $order['trackNumber'] !== '' ? $order['trackNumber'] : null;
        $status = $this->status($order)->status;
        $template = (string) config('gulfcart.cj.tracking_url', '');

        return new TrackingInfo(
            is_string($order['logisticName'] ?? null) ? $order['logisticName'] : null,
            $number,
            $number !== null && $template !== '' ? str_replace('{number}', rawurlencode($number), $template) : null,
            $status,
            $number === null ? [] : [[
                'code' => strtoupper($status),
                'description' => (string) ($order['orderStatus'] ?? $status),
                'location' => null,
                'occurred_at' => now()->toIso8601String(),
            ]],
        );
    }

    public function cancelOrder(string $externalOrderId): CancellationResult
    {
        $status = $this->status($this->detail($externalOrderId))->status;
        if ($status !== 'pending') {
            return new CancellationResult(false, 'CJ order is already paid or shipped; open a dispute in the CJ dashboard.');
        }

        try {
            $this->client->delete('shopping/order/deleteOrder', ['orderId' => $externalOrderId]);
        } catch (CjApiException $e) {
            return new CancellationResult(false, $e->getMessage());
        }

        return new CancellationResult(true);
    }

    /** @return array<string, mixed> */
    private function detail(string $externalOrderId): array
    {
        $data = $this->client->get('shopping/order/getOrderDetail', ['orderId' => $externalOrderId]);
        if (! is_array($data)) {
            throw new ConnectorException("CJ order {$externalOrderId} not found.");
        }

        return $data;
    }

    /** @param array<string, mixed> $order */
    private function status(array $order): SupplierOrderStatus
    {
        $raw = strtoupper((string) ($order['orderStatus'] ?? ''));

        return new SupplierOrderStatus(
            (string) ($order['orderId'] ?? ''),
            self::STATUS[$raw] ?? 'processing',
            self::orderTotal($order),
            'USD',
        );
    }

    /**
     * Total stock per vid. Inventory is not part of product/query.
     *
     * @return array<string, int>
     */
    private function inventory(string $pid): array
    {
        $data = $this->client->get('product/stock/getInventoryByPid', ['pid' => $pid]);
        $out = [];
        foreach ((array) (is_array($data) ? ($data['variantInventories'] ?? []) : []) as $v) {
            if (! is_array($v) || ! isset($v['vid'])) {
                continue;
            }
            $total = 0;
            foreach ((array) ($v['inventory'] ?? []) as $row) {
                if (is_array($row)) {
                    $total += (int) ($row['totalInventory'] ?? $row['totalInventoryNum'] ?? 0);
                }
            }
            $out[(string) $v['vid']] = $total;
        }

        return $out;
    }

    private static function stockStatus(?int $units): string
    {
        return match (true) {
            $units === null => 'unknown',
            $units <= 0 => 'out_of_stock',
            $units < (int) config('gulfcart.cj.low_stock_units', 10) => 'low_stock',
            default => 'in_stock',
        };
    }

    /** CJ sometimes returns ranges like "3.20 -- 4.10" at product level; take the upper bound. */
    private static function price(mixed $value): string
    {
        $parts = preg_split('/\s*-{1,2}\s*/', trim((string) $value)) ?: [];
        $number = end($parts);
        if (! is_string($number) || ! is_numeric($number)) {
            throw new ConnectorException('CJ returned no usable price.');
        }

        return Decimal::round($number, 4);
    }

    /** @param array<string, mixed> $order */
    private static function orderTotal(array $order): ?string
    {
        foreach (['orderAmount', 'totalAmount'] as $key) {
            if (isset($order[$key]) && is_numeric($order[$key])) {
                return Decimal::round((string) $order[$key], 4);
            }
        }
        if (isset($order['productAmount'], $order['postageAmount']) && is_numeric($order['productAmount']) && is_numeric($order['postageAmount'])) {
            return Decimal::add((string) $order['productAmount'], (string) $order['postageAmount']);
        }

        return null;
    }

    private function regionName(string $country, string $code): string
    {
        foreach (app(AddressRules::class)->regions($country) as $region) {
            if ($region['code'] === $code) {
                return $region['en'];
            }
        }

        return $code;
    }

    private function fromCountry(): string
    {
        return strtoupper((string) ($this->supplier->metadata['cj']['from_country'] ?? config('gulfcart.cj.from_country', 'CN')));
    }

    /** @return list<string> */
    private function preferredLogistics(): array
    {
        $names = $this->supplier->metadata['cj']['logistics'] ?? config('gulfcart.cj.logistics', []);

        return array_values(array_filter(array_map('trim', is_array($names) ? $names : explode(',', (string) $names))));
    }

    private function autoPay(): bool
    {
        return (bool) ($this->supplier->metadata['cj']['auto_pay'] ?? config('gulfcart.cj.auto_pay', true));
    }

    private function apiKey(): string
    {
        $ref = (string) $this->supplier->credentials_ref;
        if (str_starts_with($ref, 'config:')) {
            return (string) config(substr($ref, 7), '');
        }

        return (string) config('gulfcart.cj.api_key', '');
    }
}
