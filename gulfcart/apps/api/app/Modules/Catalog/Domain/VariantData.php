<?php

namespace App\Modules\Catalog\Domain;

/** Read-only variant view for other modules (cart, pricing, orders). */
final class VariantData
{
    /**
     * @param  array<string, string>  $attributes
     * @param  array<string, string|null>  $productTitle
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $sku,
        public readonly array $attributes,
        public readonly bool $isActive,
        public readonly array $productTitle,
        public readonly string $productSlug,
        public readonly string $productStatus,
        public readonly ?string $categoryId,
        public readonly ?string $brand,
        public readonly ?int $weightGrams,
        public readonly ?string $hsCode,
        public readonly ?string $imageUrl,
    ) {}

    public function isPurchasable(): bool
    {
        return $this->isActive && $this->productStatus === ProductStatus::PUBLISHED;
    }

    /** @return array<string, mixed> immutable snapshot stored on order items */
    public function snapshot(): array
    {
        return [
            'product_id' => $this->productId,
            'variant_id' => $this->id,
            'sku' => $this->sku,
            'title' => $this->productTitle,
            'slug' => $this->productSlug,
            'brand' => $this->brand,
            'attributes' => $this->attributes,
            'image_url' => $this->imageUrl,
            'weight_grams' => $this->weightGrams,
            'hs_code' => $this->hsCode,
        ];
    }
}
