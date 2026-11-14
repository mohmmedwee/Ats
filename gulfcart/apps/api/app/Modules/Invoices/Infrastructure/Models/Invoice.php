<?php

namespace App\Modules\Invoices\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $number
 * @property string $type
 * @property string $order_id
 * @property string|null $refund_id
 * @property string|null $original_invoice_id
 * @property string $country
 * @property string $currency
 * @property array<string, string|null> $seller
 * @property array<string, string|null> $buyer
 * @property list<array<string, mixed>> $lines
 * @property string $net_total
 * @property string $vat_total
 * @property string $gross_total
 * @property string|null $qr_payload
 * @property Carbon $issued_at
 */
class Invoice extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['seller' => 'array', 'buyer' => 'array', 'lines' => 'array', 'issued_at' => 'datetime'];
    }
}
