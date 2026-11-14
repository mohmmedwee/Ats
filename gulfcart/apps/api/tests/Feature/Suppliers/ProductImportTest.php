<?php

namespace Tests\Feature\Suppliers;

use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Jobs\FetchProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

final class ProductImportTest extends TestCase
{
    use CatalogFixtures;

    private const HEADER = 'external_product_id,external_variant_id,sku,title_en,title_ar,description_en,description_ar,category_slug,brand,attributes,cost_price,cost_currency,stock_status,image_url,weight_grams,hs_code,countries';

    private function upload(string $supplierId, array $lines): TestResponse
    {
        $csv = implode("\n", array_merge([self::HEADER], $lines))."\n";

        return $this->post('/api/v1/admin/imports/products', [
            'supplier_id' => $supplierId,
            'file' => UploadedFile::fake()->createWithContent('feed.csv', $csv),
        ], $this->asStaff(['catalog_manager']) + ['Accept' => 'application/json']);
    }

    public function test_valid_rows_create_draft_products_and_invalid_rows_are_reported(): void
    {
        Storage::fake('local');
        Queue::fake([FetchProductImage::class]);
        $this->category('dresses');
        $supplier = $this->supplier(['AE', 'SA']);

        $id = $this->upload($supplier->id, [
            'P1,P1-S,DRS-S,Summer Dress,فستان صيفي,Light dress,,dresses,Acme,size=S;color=Black,24.00,USD,in_stock,https://cdn.test/p1.jpg,400,620442,AE|SA',
            'P1,P1-M,DRS-M,Summer Dress,فستان صيفي,Light dress,,dresses,Acme,size=M;color=Black,25.50,USD,low_stock,,400,620442,AE|SA',
            'P2,P2-1,BAG-1,Bag,,,,,Acme,,abc,USD,in_stock,,,,AE',
            'P3,P3-1,SHOE-1,Shoe,,,,unknown-cat,,,10,USD,in_stock,,,,QA',
        ])->assertStatus(202)->json('data.id');

        $import = $this->getJson("/api/v1/admin/imports/{$id}", $this->asStaff(['catalog_manager']))->assertOk()->json('data');
        $this->assertSame('completed', $import['status']);
        $this->assertSame(1, $import['created_count']);
        $this->assertSame(2, $import['error_count']);
        $this->assertSame([4, 5], array_column($import['errors'], 'row'));

        $product = Product::query()->with('variants')->sole();
        $this->assertSame('draft', $product->status);
        $this->assertEquals(['en' => 'Summer Dress', 'ar' => 'فستان صيفي'], $product->title);
        $this->assertCount(2, $product->variants);
        $this->assertSame(['size' => 'S', 'color' => 'Black'], $product->variants->firstWhere('sku', 'DRS-S')->attributes);
        // Supplier authorization allows image use, so imported image rights are pre-approved.
        $this->assertSame('approved', ProductImage::query()->sole()->rights_status);
        $this->assertDatabaseCount('supplier_variant_mappings', 2);
    }

    public function test_reimport_updates_instead_of_duplicating(): void
    {
        Storage::fake('local');
        $supplier = $this->supplier(['AE']);
        $row = fn (string $cost) => "P1,P1-S,DRS-S,Summer Dress,فستان,,,,,size=S,{$cost},USD,in_stock,,,,AE";

        $this->upload($supplier->id, [$row('24.00')])->assertStatus(202);
        $this->upload($supplier->id, [$row('22.00')])->assertStatus(202);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('supplier_variant_mappings', ['cost_price' => '22.0000']);
    }

    public function test_missing_columns_fail_the_import(): void
    {
        Storage::fake('local');
        $supplier = $this->supplier(['AE']);
        $id = $this->post('/api/v1/admin/imports/products', [
            'supplier_id' => $supplier->id,
            'file' => UploadedFile::fake()->createWithContent('feed.csv', "sku,title_en\nA,B\n"),
        ], $this->asStaff(['catalog_manager']) + ['Accept' => 'application/json'])->json('data.id');

        $this->getJson("/api/v1/admin/imports/{$id}", $this->asStaff(['catalog_manager']))
            ->assertJsonPath('data.status', 'failed');
    }
}
