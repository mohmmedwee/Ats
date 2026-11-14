<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Jobs\FetchProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class CatalogAdminTest extends TestCase
{
    use CatalogFixtures;

    public function test_category_tree_management_prevents_cycles_and_orphaning(): void
    {
        $h = $this->asStaff(['catalog_manager']);
        $women = $this->postJson('/api/v1/admin/categories', ['slug' => 'women', 'name' => ['en' => 'Women', 'ar' => 'نساء']], $h)
            ->assertCreated()->json('data.id');
        $dresses = $this->postJson('/api/v1/admin/categories', ['slug' => 'dresses', 'name' => ['en' => 'Dresses', 'ar' => 'فساتين'], 'parent_id' => $women], $h)
            ->assertCreated()->json('data.id');

        $this->assertErrorCode($this->patchJson("/api/v1/admin/categories/{$women}", ['parent_id' => $dresses], $h), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->deleteJson("/api/v1/admin/categories/{$women}", [], $h), 409, 'CONFLICT');
        $this->deleteJson("/api/v1/admin/categories/{$dresses}", [], $h)->assertNoContent();
    }

    public function test_catalog_endpoints_require_catalog_permission(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/admin/products', $this->asStaff(['support'])), 403, 'FORBIDDEN');
    }

    public function test_full_product_lifecycle_from_draft_to_published(): void
    {
        Storage::fake('public');
        $h = $this->asStaff(['catalog_manager']);

        $id = $this->postJson('/api/v1/admin/products', [
            'title' => ['en' => 'Linen Shirt'], 'brand' => 'Acme', 'weight_grams' => 300, 'hs_code' => '620520',
        ], $h)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->postJson("/api/v1/admin/products/{$id}/variants", ['sku' => 'LS-M', 'attributes' => ['size' => 'M']], $h)->assertCreated();
        $imageId = $this->post("/api/v1/admin/products/{$id}/images", [
            'file' => UploadedFile::fake()->image('shirt.jpg'), 'rights_basis' => 'own_photo',
        ], $h + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.rights_status', 'pending')->json('data.id');
        $this->putJson("/api/v1/admin/products/{$id}/availability", ['countries' => [
            ['country' => 'AE', 'is_available' => true, 'min_days' => 5, 'max_days' => 9],
        ]], $h)->assertOk();

        // Publishing straight from draft is not a legal transition.
        $this->assertErrorCode($this->postJson("/api/v1/admin/products/{$id}/publish", [], $h), 409, 'ILLEGAL_STATE_TRANSITION');

        $this->postJson("/api/v1/admin/products/{$id}/submit", [], $h)->assertOk()->assertJsonPath('data.status', 'pending_review');
        $problems = $this->assertErrorCode($this->postJson("/api/v1/admin/products/{$id}/publish", [], $h), 422, 'PRODUCT_NOT_PUBLISHABLE')
            ->json('error.details.problems');
        $this->assertEqualsCanonicalizing(['TITLE_TRANSLATION_MISSING', 'NO_APPROVED_IMAGE'], $problems);

        $this->patchJson("/api/v1/admin/products/{$id}", ['title' => ['en' => 'Linen Shirt', 'ar' => 'قميص كتان']], $h)->assertOk();
        $this->patchJson("/api/v1/admin/images/{$imageId}", ['rights_status' => 'approved'], $h)->assertOk();

        $this->postJson("/api/v1/admin/products/{$id}/publish", [], $h)
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            // Published, but not sellable until an authorized supplier source exists.
            ->assertJsonPath('data.availability.0.is_sellable', false)
            ->assertJsonPath('data.availability.0.unsellable_reason', 'NO_AUTHORIZED_SOURCE');

        $this->assertDatabaseHas('audit_logs', ['action' => 'product.status_changed', 'entity_id' => $id]);
    }

    public function test_url_images_are_downloaded_asynchronously(): void
    {
        Queue::fake();
        $product = Product::query()->create(['title' => ['en' => 'X'], 'slug' => 'x', 'status' => 'draft', 'attributes' => []]);

        $this->postJson("/api/v1/admin/products/{$product->id}/images", [
            'source_url' => 'https://cdn.supplier.test/x.jpg', 'rights_basis' => 'supplier_agreement',
        ], $this->asStaff(['catalog_manager']))->assertCreated()->assertJsonPath('data.downloaded', false);

        Queue::assertPushed(FetchProductImage::class);
    }

    public function test_variant_sku_must_be_unique(): void
    {
        ['product' => $p, 'variant' => $v] = $this->sellableProduct();

        $this->assertErrorCode(
            $this->postJson("/api/v1/admin/products/{$p->id}/variants", ['sku' => $v->sku, 'attributes' => ['size' => 'L']], $this->asStaff(['catalog_manager'])),
            422, 'VALIDATION_FAILED',
        );
    }
}
