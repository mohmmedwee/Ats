<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $product_id
 * @property string|null $variant_id
 * @property string|null $storage_path
 * @property string|null $source_url
 * @property string $rights_status
 * @property string|null $rights_basis
 * @property array<string, string|null>|null $alt_text
 * @property int $sort_order
 */
class ProductImage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['alt_text' => 'array', 'sort_order' => 'integer'];
    }

    /** Images are shown only when rights are approved and the file is in our storage (never hotlinked). */
    public function isDisplayable(): bool
    {
        return $this->rights_status === 'approved' && $this->storage_path !== null;
    }

    public function publicUrl(): ?string
    {
        return $this->storage_path !== null
            ? Storage::disk((string) config('gulfcart.catalog_disk', 'public'))->url($this->storage_path)
            : null;
    }
}
