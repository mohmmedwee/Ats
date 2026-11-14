<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Jobs\FetchProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class ImageFetchTest extends TestCase
{
    private function image(string $url): ProductImage
    {
        $p = Product::query()->create(['title' => ['en' => 'X'], 'slug' => 'x-'.uniqid(), 'status' => 'draft', 'attributes' => []]);

        return ProductImage::query()->create(['product_id' => $p->id, 'source_url' => $url, 'rights_status' => 'approved']);
    }

    public function test_image_is_copied_into_our_storage(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('a.png', 10, 10);
        $png = (string) file_get_contents($file->getPathname());
        Http::fake(['cdn.supplier.test/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $image = $this->image('https://cdn.supplier.test/a.png');

        (new FetchProductImage($image->id))->handle();

        $image->refresh();
        $this->assertNotNull($image->storage_path);
        Storage::disk('public')->assertExists($image->storage_path);
        $this->assertTrue($image->isDisplayable());
    }

    public function test_non_image_content_is_rejected(): void
    {
        Storage::fake('public');
        Http::fake(['cdn.supplier.test/*' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'image/png'])]);
        $image = $this->image('https://cdn.supplier.test/fake.png');

        $this->expectException(RuntimeException::class);
        (new FetchProductImage($image->id))->handle();
    }

    public function test_plain_http_urls_are_rejected(): void
    {
        Http::fake();
        $image = $this->image('http://cdn.supplier.test/a.png');

        $this->expectException(RuntimeException::class);
        (new FetchProductImage($image->id))->handle();
        Http::assertNothingSent();
    }
}
