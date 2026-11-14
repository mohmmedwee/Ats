<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AvailabilityRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'countries' => ['required', 'array', 'min:1'],
            'countries.*.country' => ['required', 'string', 'size:2', 'distinct'],
            'countries.*.is_available' => ['required', 'boolean'],
            'countries.*.min_days' => ['nullable', 'integer', 'between:1,90'],
            'countries.*.max_days' => ['nullable', 'integer', 'between:1,90', 'gte:countries.*.min_days'],
            'countries.*.restriction_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
