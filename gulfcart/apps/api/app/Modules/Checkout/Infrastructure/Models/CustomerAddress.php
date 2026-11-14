<?php

namespace App\Modules\Checkout\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $user_id
 * @property string $country_id
 * @property string $full_name
 * @property string $phone
 * @property string $address_line_1
 * @property string|null $address_line_2
 * @property string|null $district
 * @property string $city
 * @property string $state
 * @property string|null $postal_code
 * @property string|null $national_address_code
 * @property bool $is_default
 */
class CustomerAddress extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'full_name' => 'encrypted',
            'phone' => 'encrypted',
            'address_line_1' => 'encrypted',
            'address_line_2' => 'encrypted',
            'national_address_code' => 'encrypted',
        ];
    }

    /** @return array<string, string|null> immutable copy stored on orders and sent to suppliers */
    public function snapshot(string $countryCode): array
    {
        return $this->only(['full_name', 'phone', 'address_line_1', 'address_line_2', 'district', 'city', 'state', 'postal_code', 'national_address_code'])
            + ['country' => $countryCode];
    }
}
