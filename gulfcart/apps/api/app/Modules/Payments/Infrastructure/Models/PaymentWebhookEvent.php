<?php

namespace App\Modules\Payments\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $status
 */
class PaymentWebhookEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
