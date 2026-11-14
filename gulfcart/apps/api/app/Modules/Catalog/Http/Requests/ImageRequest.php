<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required_without:source_url', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'source_url' => ['required_without:file', 'url:https', 'max:2000'],
            'variant_id' => ['nullable', 'uuid'],
            'rights_basis' => ['required', 'string', 'in:supplier_agreement,own_photo,licensed'],
            'alt_text' => ['sometimes', 'array'],
            'alt_text.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
