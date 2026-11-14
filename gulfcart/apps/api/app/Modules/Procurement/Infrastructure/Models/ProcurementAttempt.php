<?php

namespace App\Modules\Procurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $procurement_task_id
 * @property int $attempt_number
 * @property string $action
 * @property string $outcome
 * @property string|null $error_class
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $response_payload
 */
class ProcurementAttempt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['request_payload' => 'array', 'response_payload' => 'array'];
    }
}
