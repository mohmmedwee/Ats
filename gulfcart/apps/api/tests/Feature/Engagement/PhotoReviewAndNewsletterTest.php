<?php

namespace Tests\Feature\Engagement;

use App\Modules\Promotions\Infrastructure\Models\Coupon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class PhotoReviewAndNewsletterTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_review_photos_are_re_encoded_and_shown_after_approval(): void
    {
        Storage::fake('public');
        ['product' => $product, 'variant' => $variant] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $order = $this->placeOrder([[$variant, 1]], $auth);
        DB::table('order_items')->where('order_id', $order->id)->update(['fulfillment_status' => 'delivered']);

        $this->post("/api/v1/products/{$product->id}/reviews", ['rating' => 5, 'photos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]], $auth + ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post("/api/v1/products/{$product->id}/reviews", ['rating' => 5, 'photos' => array_fill(0, 4, UploadedFile::fake()->image('p.jpg', 400, 400))], $auth + ['Accept' => 'application/json'])->assertUnprocessable();

        $id = $this->post("/api/v1/products/{$product->id}/reviews", ['rating' => 5, 'body' => 'Lovely', 'photos' => [UploadedFile::fake()->image('a.png', 2400, 1800), UploadedFile::fake()->image('b.jpg', 600, 800)]], $auth + ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        Storage::disk('public')->assertExists("reviews/{$id}/1.jpg");
        Storage::disk('public')->assertExists("reviews/{$id}/2.jpg");
        [$w] = getimagesizefromstring((string) Storage::disk('public')->get("reviews/{$id}/1.jpg"));
        $this->assertSame(1600, $w); // resized, JPEG

        $this->resetAuth();
        $this->postJson("/api/v1/admin/reviews/{$id}/approve", [], $this->asStaff(['catalog_manager']))->assertOk();
        $this->resetAuth();
        $photos = $this->getJson("/api/v1/products/{$product->id}/reviews")->assertOk()->json('data.reviews.0.photos');
        $this->assertCount(2, $photos);
    }

    public function test_newsletter_sign_up_gives_the_welcome_code_once_and_can_be_undone(): void
    {
        Coupon::query()->create(['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'countries' => [], 'per_customer_limit' => 1, 'is_active' => true]);
        config(['gulfcart.newsletter.welcome_code' => 'welcome10']);

        $this->postJson('/api/v1/newsletter', ['email' => 'Sara@Example.com'], ['X-Country' => 'AE'])->assertCreated()->assertJsonPath('data.welcome_code', 'WELCOME10');
        $this->postJson('/api/v1/newsletter', ['email' => 'sara@example.com'], ['X-Country' => 'AE'])->assertOk()->assertJsonPath('data.welcome_code', null);
        $this->postJson('/api/v1/newsletter', ['email' => 'not-an-email'], ['X-Country' => 'AE'])->assertUnprocessable();

        $token = DB::table('newsletter_subscribers')->value('unsubscribe_token');
        $this->postJson('/api/v1/newsletter/unsubscribe', ['token' => $token])->assertOk()->assertJsonPath('data.changed', true);

        $staff = $this->asStaff(['finance']);
        $this->getJson('/api/v1/admin/newsletter', $staff)->assertOk()->assertJsonPath('data.active', 0)->assertJsonPath('data.unsubscribed', 1);
        $this->postJson('/api/v1/newsletter', ['email' => 'ali@example.com'], ['X-Country' => 'SA'])->assertCreated();
        $csv = $this->get('/api/v1/admin/newsletter?format=csv', $staff)->assertOk()->streamedContent();
        $this->assertStringContainsString('ali@example.com', $csv);
        $this->assertStringNotContainsString('sara@example.com', $csv);
    }
}
