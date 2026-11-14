<?php

namespace Tests\Feature\Engagement;

use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixtures;
use Tests\Support\OrderFlow;
use Tests\TestCase;

final class EngagementTest extends TestCase
{
    use CatalogFixtures, OrderFlow;

    public function test_wishlist_saves_products_and_listing_filters_by_ids(): void
    {
        ['product' => $a] = $this->sellableProduct(['AE']);
        ['product' => $b] = $this->sellableProduct(['AE']);
        $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();

        $this->postJson('/api/v1/wishlist', ['product_id' => $a->id], $auth)->assertCreated();
        $this->postJson('/api/v1/wishlist', ['product_id' => $a->id], $auth)->assertCreated();
        $this->postJson('/api/v1/wishlist', ['product_id' => $b->id], $auth)->assertCreated();
        $ids = $this->getJson('/api/v1/wishlist', $auth)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);

        $this->getJson('/api/v1/products?ids='.implode(',', $ids), ['X-Country' => 'AE'])->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/wishlist/{$a->id}", [], $auth)->assertNoContent();
        $this->assertSame([$b->id], $this->getJson('/api/v1/wishlist', $auth)->json('data'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/wishlist')->assertUnauthorized();
    }

    public function test_only_customers_who_received_the_product_can_review_and_reviews_show_after_approval(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->sellableProduct(['AE']);
        $auth = $this->customerAuth();
        $this->postJson("/api/v1/products/{$product->id}/reviews", ['rating' => 5], $auth)->assertForbidden()->assertJsonPath('error.code', 'REVIEW_NOT_ALLOWED');

        $order = $this->placeOrder([[$variant, 1]], $auth);
        DB::table('order_items')->where('order_id', $order->id)->update(['fulfillment_status' => 'delivered']);

        $this->getJson("/api/v1/products/{$product->id}/reviews", $auth)->assertJsonPath('data.mine.can_review', true);
        $id = $this->postJson("/api/v1/products/{$product->id}/reviews", ['rating' => 4, 'title' => 'Nice', 'body' => 'Good fabric'], $auth)
            ->assertCreated()->assertJsonPath('data.verified', true)->json('data.id');
        $this->postJson("/api/v1/products/{$product->id}/reviews", ['rating' => 1], $auth)->assertStatus(409);
        $this->getJson("/api/v1/products/{$product->id}/reviews")->assertJsonPath('data.count', 0);

        $staff = $this->asStaff(['catalog_manager']);
        $this->getJson('/api/v1/admin/reviews', $staff)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/admin/reviews/{$id}/approve", [], $this->asStaff(['catalog_manager']))->assertOk()->assertJsonPath('data.status', 'approved');

        $this->getJson("/api/v1/products/{$product->id}/reviews")->assertOk()
            ->assertJsonPath('data.count', 1)->assertJsonPath('data.average', 4)->assertJsonPath('data.distribution.4', 1)
            ->assertJsonPath('data.reviews.0.title', 'Nice');
    }

    public function test_size_guide_comes_from_the_category_or_its_parent(): void
    {
        $parent = $this->category('women');
        $this->putSizeGuide($parent, "## Sizes\n- S: 36\n- M: 38");
        $child = $this->category('dresses', $parent->id);
        ['product' => $p] = $this->sellableProduct(['AE'], ['category_id' => $child->id]);

        $this->getJson("/api/v1/products/{$p->slug}", ['X-Country' => 'AE'])->assertOk()->assertJsonPath('data.size_guide', "## Sizes\n- S: 36\n- M: 38");
        $plain = Product::query()->whereKeyNot($p->id)->first();
        $this->assertNull($plain);
    }

    private function putSizeGuide(Category $category, string $text): void
    {
        $this->patchJson("/api/v1/admin/categories/{$category->id}", ['size_guide' => ['en' => $text, 'ar' => $text]], $this->asStaff(['catalog_manager']))->assertOk();
    }
}
