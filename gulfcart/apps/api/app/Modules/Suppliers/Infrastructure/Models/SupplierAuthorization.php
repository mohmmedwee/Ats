<?php

namespace App\Modules\Suppliers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $supplier_id
 * @property string $agreement_reference
 * @property bool $allows_resale
 * @property bool $allows_api_purchasing
 * @property bool $allows_direct_shipping
 * @property bool $allows_image_use
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 */
class SupplierAuthorization extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allows_resale' => 'boolean', 'allows_api_purchasing' => 'boolean',
            'allows_direct_shipping' => 'boolean', 'allows_image_use' => 'boolean',
            'valid_from' => 'date', 'valid_until' => 'date',
        ];
    }

    /** @return list<string> parses CHAR(2)[] */
    public function countryList(): array
    {
        $raw = trim((string) $this->getRawOriginal('countries'), '{}');

        return $raw === '' ? [] : array_map('trim', explode(',', $raw));
    }
}
