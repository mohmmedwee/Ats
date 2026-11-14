<?php

namespace App\Modules\Content\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $slug
 * @property array<string, string|null> $title
 * @property array<string, string|null> $body
 * @property bool $is_published
 * @property bool $in_footer
 * @property int $sort_order
 */
class Page extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['title' => 'array', 'body' => 'array', 'is_published' => 'boolean', 'in_footer' => 'boolean'];
    }
}
