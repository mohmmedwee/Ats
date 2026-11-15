<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Infrastructure\Models\Brand;
use App\Modules\Catalog\Infrastructure\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class BrandsTest extends TestCase
{
    use CatalogFixtures;

    public function test_brands_have_pages_filters_and_product_details(): void
    {
        Storage::fake('public');
        $staff = $this->asStaff(['catalog_manager']);
        $nova = $this->postJson('/api/v1/admin/brands', ['name' => ['en' => 'Nova', 'ar' => 'نوفا'], 'is_authorized' => true, 'is_featured' => true], $staff)
            ->assertCreated()->assertJsonPath('data.slug', 'nova')->json('data');
        $this->post("/api/v1/admin/brands/{$nova['id']}/logo", ['file' => UploadedFile::fake()->image('logo.png', 200, 80)], $this->asStaff(['catalog_manager']) + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.logo_url', fn ($u) => is_string($u) && str_contains($u, "brands/{$nova['id']}"));

        ['product' => $phone] = $this->sellableProduct(['AE']);
        ['product' => $other] = $this->sellableProduct(['AE']);
        $this->patchJson("/api/v1/admin/products/{$phone->id}", [
            'brand_id' => $nova['id'],
            'specifications' => ['en' => "Display: 6.5\"\nBattery: 5000 mAh", 'ar' => "الشاشة: 6.5 بوصة\nالبطارية: 5000"],
            'warranty' => ['en' => '1-year warranty', 'ar' => 'ضمان سنة'],
        ], $this->asStaff(['catalog_manager']))->assertOk()->assertJsonPath('data.brand', 'Nova');

        $h = ['X-Country' => 'AE'];
        $this->getJson('/api/v1/brands?featured=1', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_count', 1);
        $this->getJson('/api/v1/products?brand=nova', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.brand_slug', 'nova');
        $this->getJson('/api/v1/products/facets', $h)->assertOk()->assertJsonPath('data.brands.0.slug', 'nova')->assertJsonPath('data.brands.0.count', 1);
        $this->getJson("/api/v1/products/{$phone->slug}", $h + ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.brand_info.authorized', true)->assertJsonPath('data.warranty', 'ضمان سنة')
            ->assertJsonPath('data.specifications', "الشاشة: 6.5 بوصة\nالبطارية: 5000");
        $this->assertNotContains($other->id, array_column($this->getJson('/api/v1/products?brand=nova', $h)->json('data'), 'id'));
    }

    public function test_sections_that_require_authorized_brands_block_publishing_otherwise(): void
    {
        $electronics = $this->category('electronics');
        $electronics->update(['requires_authorized_brand' => true]);
        $mobiles = $this->category('mobiles', $electronics->id);
        $grey = Brand::query()->create(['slug' => 'grey-import', 'name' => ['en' => 'Grey', 'ar' => 'رمادي'], 'is_authorized' => false]);
        ['product' => $p] = $this->sellableProduct(['AE'], ['status' => 'pending_review', 'category_id' => $mobiles->id]);
        $p->update(['brand_id' => $grey->id]);

        $this->postJson("/api/v1/admin/products/{$p->id}/publish", [], $this->asStaff(['catalog_manager']))
            ->assertStatus(422)->assertJsonPath('error.details.problems', ['BRAND_NOT_AUTHORIZED']);

        $grey->update(['is_authorized' => true]);
        $this->postJson("/api/v1/admin/products/{$p->id}/publish", [], $this->asStaff(['catalog_manager']))->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertTrue(Category::query()->find($mobiles->id)->requiresAuthorizedBrand());
    }
}
