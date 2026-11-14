<?php

namespace App\Modules\Suppliers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $supplier_id
 * @property string|null $uploaded_by
 * @property string $file_path
 * @property string $status
 * @property int $total_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $error_count
 * @property list<array{row: int, errors: list<string>}> $errors
 */
class ProductImport extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['errors' => 'array', 'completed_at' => 'datetime'];
    }
}
