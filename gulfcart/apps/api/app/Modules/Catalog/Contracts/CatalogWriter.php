<?php

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Domain\ProductDraft;

/** Used by feed/CSV ingestion (Suppliers) to create catalog entries. Products are always created as drafts. */
interface CatalogWriter
{
    public function createProduct(ProductDraft $draft): string;

    public function updateDraftProduct(string $productId, ProductDraft $draft): void;

    /** @param array<string, string> $attributes */
    public function upsertVariant(string $productId, string $sku, array $attributes, ?int $weightGrams = null): string;

    /** Adds an image by URL (downloaded asynchronously). Skips URLs already attached. */
    public function addImageFromUrl(string $productId, string $url, bool $rightsApproved, ?string $rightsBasis): void;
}
