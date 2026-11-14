<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $user_id
 * @property string $channel
 * @property string $template
 * @property string $status
 * @property string $dedupe_key
 */
class NotificationLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];
}
