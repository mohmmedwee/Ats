<?php

namespace Tests\Feature\Orders;

use App\Modules\Notifications\Application\TemplateMail;
use App\Modules\Notifications\Infrastructure\Models\NotificationLog;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Suppliers\Application\Connectors\MockSupplierConnector;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class ReturnsAndNotificationsTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    /** @return array{0: Order, 1: array<string, string>, 2: string} order, auth, order item id */
    private function deliveredOrder(int $qty = 2): array
    {
        $fx = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$fx['variant'], $qty]], $auth);
        $this->payOrder($order, $auth);
        $so = $this->supplierOrders($order)->first();
        /** @var MockSupplierConnector $mock */
        $mock = app(ConnectorResolver::class)->forSupplier($fx['supplier']->id);
        $mock->advance($so->external_order_id, 'delivered');
        $this->artisan('procurement:track')->assertSuccessful();
        $this->assertSame('delivered', $order->refresh()->status);

        return [$order, $auth, $order->items()->value('id')];
    }

    public function test_customer_returns_one_unit_and_gets_a_partial_refund(): void
    {
        [$order, $auth, $itemId] = $this->deliveredOrder(2);

        $returnId = $this->postJson("/api/v1/orders/{$order->id}/return", ['items' => [
            ['order_item_id' => $itemId, 'quantity' => 1, 'reason_code' => 'size_fit'],
        ]], $auth)->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');

        // The second unit is still available; asking for two more is not.
        $this->assertErrorCode($this->postJson("/api/v1/orders/{$order->id}/return", ['items' => [
            ['order_item_id' => $itemId, 'quantity' => 2, 'reason_code' => 'size_fit'],
        ]], $auth), 422, 'ITEM_NOT_RETURNABLE');

        $staff = $this->asStaff(['operations']);
        $this->assertErrorCode($this->postJson("/api/v1/admin/returns/{$returnId}/refund", [], $staff + ['Idempotency-Key' => 'return-refund-early-1']), 409, 'ILLEGAL_STATE_TRANSITION');
        $this->postJson("/api/v1/admin/returns/{$returnId}/approve", [], $staff + ['Idempotency-Key' => 'return-approve-00001'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/admin/returns/{$returnId}/receive", [], $staff + ['Idempotency-Key' => 'return-receive-00001'])->assertOk();
        $this->postJson("/api/v1/admin/returns/{$returnId}/refund", [], $staff + ['Idempotency-Key' => 'return-refund-000001'])->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $order->refresh();
        $unit = (string) $order->items()->value('unit_gross_price');
        $this->assertSame($unit, $order->refunded_total);
        $this->assertSame('partial', $order->refund_state);
        $this->assertSame(1, $order->items()->value('refunded_quantity'));
        $this->assertDatabaseHas('refunds', ['return_id' => $returnId, 'status' => 'succeeded', 'reason_code' => 'customer_return']);
    }

    public function test_undelivered_items_and_closed_windows_are_not_returnable(): void
    {
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$v, 1]], $auth);
        $this->payOrder($order, $auth);
        $this->assertErrorCode($this->postJson("/api/v1/orders/{$order->id}/return", ['items' => [
            ['order_item_id' => $order->items()->value('id'), 'quantity' => 1, 'reason_code' => 'changed_mind'],
        ]], $auth), 422, 'ITEM_NOT_RETURNABLE');

        $this->resetAuth();
        [$delivered, $auth2, $itemId] = $this->deliveredOrder(1);
        $this->travel(15)->days();
        $this->assertErrorCode($this->postJson("/api/v1/orders/{$delivered->id}/return", ['items' => [
            ['order_item_id' => $itemId, 'quantity' => 1, 'reason_code' => 'changed_mind'],
        ]], $auth2), 422, 'RETURN_WINDOW_CLOSED');
    }

    public function test_order_emails_are_sent_once_in_the_customer_language(): void
    {
        Mail::fake();
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $customer = $this->customer(['preferred_locale' => 'ar', 'email' => 'sara@example.com']);
        $auth = $this->customerAuth($customer);
        $order = $this->placeOrder([[$v, 1]], $auth);
        $this->payOrder($order, $auth);

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $m) => $m->hasTo('sara@example.com')
            && $m->subjectLine === "تم تأكيد الطلب {$order->order_number}" && $m->mailLocale === 'ar');

        // Redeliver the order.paid event (at-least-once): no second email.
        OutboxEvent::query()->where('event_type', 'order.paid')->update(['status' => 'pending']);
        $this->artisan('outbox:dispatch')->assertSuccessful();
        Mail::assertSent(TemplateMail::class, fn (TemplateMail $m) => str_contains($m->subjectLine, $order->order_number) && str_starts_with($m->subjectLine, 'تم تأكيد'));
        $this->assertSame(1, NotificationLog::query()->where('template', 'order_confirmed')->count());
        $this->assertDatabaseMissing('notification_logs', ['recipient_hash' => 'sara@example.com']);
    }

    public function test_operations_are_alerted_on_procurement_exceptions(): void
    {
        Mail::fake();
        $fx = $this->sellableProduct(['AE']);
        $this->setMock($fx['supplier'], ['purchase_mode' => 'reject']);
        $auth = $this->customerAuth();
        $this->payOrder($this->placeOrder([[$fx['variant'], 1]], $auth), $auth);

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $m) => $m->hasTo('operations@gulfcart.local') && $m->subjectLine === 'Procurement exception: SUPPLIER_REJECTED');
    }

    public function test_phone_only_customers_get_an_sms_and_whatsapp_replaces_it_when_configured(): void
    {
        Mail::fake();
        $texts = [];
        $this->app->instance(\App\Modules\Identity\Contracts\SmsSender::class, new class($texts) implements \App\Modules\Identity\Contracts\SmsSender
        {
            public function __construct(private array &$texts) {}

            public function send(string $e164Phone, string $message): void
            {
                $this->texts[] = $message;
            }
        });
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth($this->customer(['email' => null, 'phone' => '+971501112233']));
        $order = $this->placeOrder([[$v, 1]], $auth);
        $this->payOrder($order, $auth);

        $this->assertDatabaseHas('notification_logs', ['template' => 'order_confirmed', 'channel' => 'sms', 'status' => 'sent']);
        $this->assertStringContainsString($order->order_number, $texts[0] ?? '');

        // With WhatsApp configured, the mapped template is sent instead of an SMS.
        config(['gulfcart.whatsapp.provider' => 'meta', 'gulfcart.whatsapp.phone_number_id' => '123', 'gulfcart.whatsapp.token' => 't']);
        \Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.1']]])]);
        app(\App\Modules\Notifications\Application\Notifier::class)->toCustomer($order->user_id, 'order_delivered', ['order' => $order->order_number], 'test:delivered', 'order', $order->id, "/account/orders/{$order->id}");
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/123/messages')
            && $r['template']['name'] === 'gc_order_delivered' && $r['template']['components'][0]['parameters'][1]['text'] === $order->order_number);
        $this->assertDatabaseHas('notification_logs', ['template' => 'order_delivered', 'channel' => 'whatsapp', 'status' => 'sent']);
        $this->assertCount(1, $texts);
    }

    public function test_abandoned_carts_get_one_reminder(): void
    {
        Mail::fake();
        ['variant' => $v] = $this->sellableProduct(['AE']);
        $user = $this->customer();
        $auth = $this->customerAuth($user);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $v->id, 'quantity' => 2], $auth + ['X-Country' => 'AE'])->assertCreated();
        \Illuminate\Support\Facades\DB::table('carts')->update(['updated_at' => now()->subHours(5)]);
        \Illuminate\Support\Facades\DB::table('cart_items')->update(['updated_at' => now()->subHours(5)]);

        $this->artisan('notifications:cart-reminders')->expectsOutput('1 cart reminder(s)')->assertSuccessful();
        $this->artisan('notifications:cart-reminders')->expectsOutput('0 cart reminder(s)')->assertSuccessful();
        Mail::assertSent(\App\Modules\Notifications\Application\TemplateMail::class, fn ($m) => $m->hasTo($user->email) && str_contains($m->subjectLine, 'cart'));
    }
}
