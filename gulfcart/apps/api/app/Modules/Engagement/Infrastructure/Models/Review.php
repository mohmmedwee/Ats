<?php

namespace App\Modules\Engagement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $product_id
 * @property string $user_id
 * @property string|null $order_id
 * @property int $rating
 * @property string|null $title
 * @property string|null $body
 * @property string $locale
 * @property string $status
 * @property list<string> $photos
 */
class Review extends Model
{
    use HasUuids;

    protected $table = 'product_reviews';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['photos' => '[]'];

    protected function casts(): array
    {
        return ['photos' => 'array'];
    }
}
