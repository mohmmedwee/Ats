<?php

namespace Tests\Feature\Suppliers;

use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Jobs\FetchProductImage;
use App\Modules\Procurement\Application\ProcurementEngine;
use App\Modules\Procurement\Application\Tracker;
use App\Modules\Suppliers\Application\Connectors\Cj\CjDropshippingConnector;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Domain\Connector\PurchaseLine;
use App\Modules\Suppliers\Domain\Connector\PurchaseRequest;
use App\Modules\Suppliers\Domain\Connector\PurchaseResult;
use App\Modules\Suppliers\Domain\Connector\TransientConnectorException;
use App\Modules\Suppliers\Domain\Connector\UnknownOutcomeException;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use App\Modules\Suppliers\Infrastructure\Models\SupplierProduct;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class CjConnectorTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    private const BASE = 'https://cj.test/api2.0/v1/';

    /** @var array<string, mixed> overrides per path: array (data) or callable(Request): response */
    private array $routes = [];

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['gulfcart.cj.api_key' => 'test-key', 'gulfcart.cj.base_url' => self::BASE]);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = substr(strtok($request->url(), '?'), strlen(self::BASE));
            $this->calls[] = $path;
            $route = $this->routes[$path] ?? ($path === 'authentication/getAccessToken'
                ? ['accessToken' => 'tok-1', 'accessTokenExpiryDate' => now()->addDays(15)->toIso8601String(), 'refreshToken' => 'r', 'refreshTokenExpiryDate' => now()->addDays(180)->toIso8601String()]
                : null);
            if (is_callable($route)) {
                return $route($request);
            }
            if ($route === null) {
                return Http::response(['code' => 1600101, 'result' => false, 'message' => 'Interface not found', 'data' => null]);
            }

            return Http::response(['code' => 200, 'result' => true, 'message' => 'Success', 'data' => $route, 'requestId' => 'req']);
        });
    }

    private function cjSupplier(array $metadata = []): Supplier
    {
        return $this->supplier(['AE', 'SA'], [
            'code' => 'cj', 'connector_type' => 'cj', 'credentials_ref' => 'config:gulfcart.cj.api_key',
            'metadata' => ['shipping_cost_per_unit' => '12.00', 'cj' => $metadata],
        ]);
    }

    private function catalog(string $pid, array $vids, string $price = '24.00', int $stock = 50): void
    {
        $this->routes['product/query'] = [
            'pid' => $pid, 'productNameEn' => 'Linen Summer Dress', 'productKeyEn' => 'Color-Size',
            'description' => '<p>Light <b>linen</b> dress</p>', 'bigImage' => 'https://cf.cjdropshipping.com/a.jpg', 'sellPrice' => $price,
            'variants' => array_map(fn ($vid, $i) => [
                'vid' => $vid, 'pid' => $pid, 'variantSku' => "CJLD{$i} Black", 'variantKey' => 'Black-'.['S', 'M', 'L'][$i],
                'variantSellPrice' => $price, 'variantWeight' => '350', 'variantImage' => "https://cf.cjdropshipping.com/v{$i}.jpg",
            ], $vids, array_keys($vids)),
        ];
        $this->routes['product/stock/getInventoryByPid'] = ['variantInventories' => array_map(fn ($vid) => [
            'vid' => $vid, 'inventory' => [['countryCode' => 'CN', 'totalInventory' => $stock], ['countryCode' => 'US', 'totalInventory' => 0]],
        ], $vids)];
        $this->routes['logistic/freightCalculate'] = [
            ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 9.5, 'logisticAging' => '8-15'],
            ['logisticName' => 'DHL', 'logisticPrice' => 31.2, 'logisticAging' => '3-6'],
        ];
    }

    private function request(string $pid, string $vid): PurchaseRequest
    {
        return new PurchaseRequest('SO-1', [new PurchaseLine($pid, $vid, 2, '24.00', 'USD')], [
            'full_name' => 'Sara Ahmed', 'phone' => '+971501234567', 'address_line_1' => 'Villa 1', 'district' => 'Jumeirah',
            'city' => 'Dubai', 'state' => 'DXB', 'country' => 'AE',
        ]);
    }

    public function test_product_variants_stock_and_quote_are_read_from_cj(): void
    {
        $this->catalog('P100', ['V1', 'V2'], '12.40 -- 15.80', 4);
        $connector = new CjDropshippingConnector($this->cjSupplier(['logistics' => ['DHL']]));

        $product = $connector->getProduct('P100');
        $this->assertSame('15.8000', $product->variant('V1')->cost);
        $this->assertSame('USD', $product->variant('V1')->currency);
        $this->assertSame('low_stock', $product->variant('V2')->stockStatus);

        $quote = $connector->getQuote([new PurchaseLine('P100', 'V1', 2, '15.80', 'USD')], 'AE');
        $this->assertSame('31.6000', $quote->itemsTotal);
        // Preferred logistic wins over the cheapest.
        $this->assertSame('31.2000', $quote->shippingTotal);
        $this->assertSame(1, count(array_keys($this->calls, 'authentication/getAccessToken')), 'token is cached');
        $this->assertSame(1, count(array_keys($this->calls, 'product/query')), 'product is memoized');
    }

    public function test_create_order_creates_confirms_and_pays_from_balance(): void
    {
        $this->catalog('P1', ['V1']);
        $sent = null;
        $this->routes['shopping/order/createOrderV2'] = function (Request $r) use (&$sent) {
            $sent = $r->data();

            return Http::response(['code' => 200, 'result' => true, 'data' => ['orderId' => 'CJ-778', 'orderStatus' => 'CREATED', 'productAmount' => 48, 'postageAmount' => 9.5]]);
        };
        $this->routes['shopping/order/confirmOrder'] = 'CJ-778';
        $this->routes['shopping/pay/payBalance'] = true;

        $result = (new CjDropshippingConnector($this->cjSupplier()))->createOrder($this->request('P1', 'V1'), 'so_1_v1');

        $this->assertSame(PurchaseResult::ACCEPTED, $result->outcome);
        $this->assertSame('CJ-778', $result->externalOrderId);
        $this->assertSame('57.5', rtrim(rtrim($result->totalCost, '0'), '.'));
        $this->assertSame('so_1_v1', $sent['orderNumber']);
        $this->assertSame('Dubai', $sent['shippingProvince']);
        $this->assertSame('CJPacket Ordinary', $sent['logisticName'], 'cheapest logistic by default');
        $this->assertSame([['vid' => 'V1', 'quantity' => 2]], $sent['products']);
        $this->assertSame('Villa 1, Jumeirah', $sent['shippingAddress']);
        $this->assertContains('shopping/pay/payBalance', $this->calls);
    }

    public function test_failed_balance_payment_hands_the_created_order_to_an_operator(): void
    {
        $this->catalog('P1', ['V1']);
        $this->routes['shopping/order/createOrderV2'] = ['orderId' => 'CJ-9', 'orderAmount' => '57.50'];
        $this->routes['shopping/order/confirmOrder'] = 'CJ-9';
        $this->routes['shopping/pay/payBalance'] = fn () => Http::response(['code' => 1606001, 'result' => false, 'message' => 'Insufficient balance']);

        $result = (new CjDropshippingConnector($this->cjSupplier()))->createOrder($this->request('P1', 'V1'), 'so_1_v1');

        $this->assertSame(PurchaseResult::MANUAL_REQUIRED, $result->outcome);
        $this->assertSame('CJ-9', $result->externalOrderId);
        $this->assertStringContainsString('Insufficient balance', $result->reason);
    }

    public function test_errors_are_classified_for_safe_retries(): void
    {
        $this->catalog('P1', ['V1']);
        $connector = new CjDropshippingConnector($this->cjSupplier());

        $this->routes['shopping/order/createOrderV2'] = fn () => Http::response(['code' => 1600300, 'result' => false, 'message' => 'shippingPhone is invalid']);
        $rejected = $connector->createOrder($this->request('P1', 'V1'), 'k1');
        $this->assertSame(PurchaseResult::REJECTED, $rejected->outcome);
        $this->assertStringContainsString('shippingPhone', $rejected->reason);

        $this->routes['shopping/order/createOrderV2'] = fn () => Http::response(['code' => 1600200, 'result' => false, 'message' => 'Too many requests']);
        try {
            $connector->createOrder($this->request('P1', 'V1'), 'k2');
            $this->fail('expected transient');
        } catch (TransientConnectorException) {
        }

        $this->routes['shopping/order/createOrderV2'] = fn () => throw new ConnectionException('cURL error 28: Operation timed out');
        $this->expectException(UnknownOutcomeException::class);
        $connector->createOrder($this->request('P1', 'V1'), 'k3');
    }

    public function test_expired_token_is_renewed_once(): void
    {
        $this->catalog('P1', ['V1']);
        $tokens = 0;
        $this->routes['authentication/getAccessToken'] = function () use (&$tokens) {
            $tokens++;

            return Http::response(['code' => 200, 'result' => true, 'data' => ['accessToken' => "tok-{$tokens}", 'accessTokenExpiryDate' => now()->addDays(15)->toIso8601String()]]);
        };
        $this->routes['shopping/order/getOrderDetail'] = fn (Request $r) => $r->header('CJ-Access-Token')[0] === 'tok-1'
            ? Http::response(['code' => 1600001, 'result' => false, 'message' => 'Invalid token'])
            : Http::response(['code' => 200, 'result' => true, 'data' => ['orderId' => 'CJ-1', 'orderStatus' => 'UNSHIPPED']]);

        $status = (new CjDropshippingConnector($this->cjSupplier()))->getOrderStatus('CJ-1');

        $this->assertSame('processing', $status->status);
        $this->assertSame(2, $tokens);
    }

    public function test_reconciliation_finds_the_order_by_our_reference(): void
    {
        $this->routes['shopping/order/getOrderDetail'] = fn () => Http::response(['code' => 1603100, 'result' => false, 'message' => 'Order not found']);
        $this->routes['shopping/order/list'] = ['list' => [
            ['orderId' => 'CJ-1', 'orderNum' => 'other', 'orderStatus' => 'SHIPPED'],
            ['orderId' => 'CJ-2', 'orderNum' => 'so_5_v1', 'orderStatus' => 'UNSHIPPED', 'orderAmount' => '20.00'],
        ]];
        $connector = new CjDropshippingConnector($this->cjSupplier());

        $found = $connector->findOrderByReference('so_5_v1');
        $this->assertSame('CJ-2', $found->externalOrderId);
        $this->assertSame('processing', $found->status);
        $this->assertNull($connector->findOrderByReference('so_404_v1'));
        $this->assertFalse($connector->capabilities()->idempotentOrders);
    }

    public function test_tracking_and_cancellation(): void
    {
        $this->routes['shopping/order/getOrderDetail'] = ['orderId' => 'CJ-3', 'orderStatus' => 'SHIPPED', 'trackNumber' => 'CJPKL123', 'logisticName' => 'CJPacket Ordinary'];
        $connector = new CjDropshippingConnector($this->cjSupplier());

        $tracking = $connector->getTracking('CJ-3');
        $this->assertSame('CJPKL123', $tracking->trackingNumber);
        $this->assertSame('CJPacket Ordinary', $tracking->carrier);
        $this->assertSame('shipped', $tracking->status);
        $this->assertStringContainsString('CJPKL123', $tracking->trackingUrl);
        $this->assertFalse($connector->cancelOrder('CJ-3')->cancelled);

        $this->routes['shopping/order/getOrderDetail'] = ['orderId' => 'CJ-4', 'orderStatus' => 'UNPAID'];
        $this->routes['shopping/order/deleteOrder'] = true;
        $this->assertTrue($connector->cancelOrder('CJ-4')->cancelled);
    }

    public function test_paid_order_is_purchased_from_cj_and_tracked_end_to_end(): void
    {
        $supplier = $this->cjSupplier();
        $fixture = $this->sellableProduct(['AE'], ['supplier' => $supplier]);
        $pid = $fixture['supplierProduct']->external_product_id;
        $vid = $fixture['supplierProduct']->mappings()->value('external_variant_id');
        $this->catalog($pid, [$vid]);
        $this->routes['shopping/order/createOrderV2'] = ['orderId' => 'CJ-E2E', 'orderStatus' => 'CREATED', 'orderAmount' => '57.50'];
        $this->routes['shopping/order/confirmOrder'] = 'CJ-E2E';
        $this->routes['shopping/pay/payBalance'] = true;

        $this->assertInstanceOf(CjDropshippingConnector::class, app(ConnectorResolver::class)->forSupplier($supplier->id));

        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$fixture['variant'], 2]], $auth);
        $this->payOrder($order, $auth);
        $so = $this->supplierOrders($order)->first();
        if ($so->status === 'pending') {
            app(ProcurementEngine::class)->process($so->task->id);
        }
        $so->refresh();
        $this->assertSame('purchased', $so->status);
        $this->assertSame('CJ-E2E', $so->external_order_id);

        $this->routes['shopping/order/getOrderDetail'] = ['orderId' => 'CJ-E2E', 'orderStatus' => 'SHIPPED', 'trackNumber' => 'CJPKL9', 'logisticName' => 'CJPacket Ordinary'];
        app(Tracker::class)->track($so);
        $this->assertSame('shipped', $so->refresh()->status);
        $this->assertDatabaseHas('shipments', ['tracking_number' => 'CJPKL9']);
    }

    public function test_import_command_creates_draft_products_with_shipping_estimate(): void
    {
        Storage::fake('local');
        Queue::fake([FetchProductImage::class]);
        $this->category('dresses');
        $this->cjSupplier();
        $this->catalog('P77', ['V1', 'V2', 'V3'], '18.20');

        $this->artisan('suppliers:cj-import', ['pids' => ['P77'], '--category' => 'dresses'])->assertSuccessful();

        $product = Product::query()->with('variants')->sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame('Linen Summer Dress', $product->title['en']);
        $this->assertCount(3, $product->variants);
        $this->assertEquals(['color' => 'Black', 'size' => 'M'], $product->variants->firstWhere('sku', 'CJ-CJLD1-Black')->attributes);
        $sp = SupplierProduct::query()->sole();
        $this->assertSame('18.2000', (string) $sp->cost_price);
        $this->assertSame('9.5000', $sp->supplier_data['shipping_cost']);
        $this->assertDatabaseCount('supplier_variant_mappings', 3);
    }

    public function test_setup_command_registers_an_inactive_supplier_and_checks_the_key(): void
    {
        $this->artisan('suppliers:cj-setup')->assertSuccessful();

        $supplier = Supplier::query()->where('code', 'cj')->sole();
        $this->assertSame('cj', $supplier->connector_type);
        $this->assertFalse($supplier->is_active);
        $this->assertSame('pending', $supplier->commercial_authorization_status);
        $this->assertContains('authentication/getAccessToken', $this->calls);
    }
}
