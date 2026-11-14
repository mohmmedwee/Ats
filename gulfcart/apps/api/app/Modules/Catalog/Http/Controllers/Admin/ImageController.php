<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Catalog\Application\ListingRefresher;
use App\Modules\Catalog\Http\Requests\ImageRequest;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Jobs\FetchProductImage;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ImageController
{
    public function __construct(private readonly AuditLogger $audit, private readonly ListingRefresher $listing) {}

    public function store(ImageRequest $request, Product $product): JsonResponse
    {
        $variantId = $request->input('variant_id');
        if ($variantId !== null && ! $product->variants()->whereKey($variantId)->exists()) {
            throw new ApiException('VALIDATION_FAILED', 422, ['fields' => ['variant_id' => ['Variant does not belong to this product.']]]);
        }

        $image = new ProductImage([
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'rights_status' => 'pending', // a reviewer approves rights explicitly
            'rights_basis' => $request->string('rights_basis')->toString(),
            'alt_text' => $request->input('alt_text'),
            'sort_order' => (int) $product->images()->max('sort_order') + 1,
        ]);

        if ($request->hasFile('file')) {
            $image->storage_path = $request->file('file')->store("products/{$product->id}", (string) config('gulfcart.catalog_disk', 'public'));
        } else {
            $image->source_url = $request->string('source_url')->toString();
        }
        $image->save();

        if ($image->storage_path === null) {
            FetchProductImage::dispatch($image->id);
        }
        $this->audit->record('image.added', 'product_image', $image->id, null, ['product_id' => $product->id, 'rights_basis' => $image->rights_basis]);

        return ApiResponse::success($this->present($image->refresh()), 201);
    }

    public function rights(Request $request, ProductImage $image): JsonResponse
    {
        $data = $request->validate(['rights_status' => ['required', 'in:approved,rejected']]);
        $old = $image->rights_status;
        $image->update($data);
        $this->audit->record('image.rights_reviewed', 'product_image', $image->id, ['rights_status' => $old], $data);
        $this->listing->refresh($image->product_id);

        return ApiResponse::success($this->present($image));
    }

    public function destroy(ProductImage $image): Response
    {
        $image->delete();
        $this->audit->record('image.removed', 'product_image', $image->id);
        $this->listing->refresh($image->product_id);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(ProductImage $i): array
    {
        return [
            'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'url' => $i->publicUrl(),
            'source_url' => $i->source_url, 'rights_status' => $i->rights_status, 'rights_basis' => $i->rights_basis,
            'downloaded' => $i->storage_path !== null,
        ];
    }
}
