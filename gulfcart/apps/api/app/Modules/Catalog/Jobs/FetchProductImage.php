<?php

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Events\CatalogListingStale;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Copies a supplier image into our storage. Images are never hotlinked. */
final class FetchProductImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    private const MAX_BYTES = 10 * 1024 * 1024;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(public readonly string $imageId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $image = ProductImage::query()->find($this->imageId);
        if ($image === null || $image->storage_path !== null || $image->source_url === null) {
            return;
        }
        if (! str_starts_with($image->source_url, 'https://')) {
            throw new RuntimeException('Only HTTPS image URLs are accepted.');
        }

        $response = Http::timeout(20)->retry(2, 500)->get($image->source_url);
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        if (! $response->successful() || ! isset(self::TYPES[$type]) || strlen($body) === 0 || strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException("Image download rejected (status {$response->status()}, type {$type}).");
        }
        // Verify the bytes really are an image of the declared type.
        $info = @getimagesizefromstring($body);
        if ($info === false || ($info['mime'] ?? null) !== $type) {
            throw new RuntimeException('Downloaded file is not a valid image.');
        }

        $path = "products/{$image->product_id}/{$image->id}.".self::TYPES[$type];
        Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->put($path, $body);
        $image->update(['storage_path' => $path]);

        event(new CatalogListingStale($image->product_id));
    }
}
