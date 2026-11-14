<?php

namespace App\Modules\Suppliers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $connector_type
 * @property string $base_currency
 * @property string|null $api_base_url
 * @property string|null $credentials_ref
 * @property string $commercial_authorization_status
 * @property bool $direct_shipping_enabled
 * @property bool $is_active
 * @property array<string, mixed> $metadata
 */
class Supplier extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_active' => 'boolean', 'direct_shipping_enabled' => 'boolean'];
    }

    /** @return HasMany<SupplierAuthorization, $this> */
    public function authorizations(): HasMany
    {
        return $this->hasMany(SupplierAuthorization::class)->latest('valid_from');
    }

    /** @return HasMany<SupplierProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /** The currently valid authorization, if any. */
    public function currentAuthorization(): ?SupplierAuthorization
    {
        $today = now()->toDateString();

        return $this->authorizations()
            ->where('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->where('allows_resale', true)
            ->first();
    }
}
