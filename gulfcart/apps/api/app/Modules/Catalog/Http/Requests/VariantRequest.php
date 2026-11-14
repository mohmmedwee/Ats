<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VariantRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'sku' => [$creating ? 'required' : 'sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('product_variants', 'sku')->ignore($this->route('variant')?->id)],
            'attributes' => [$creating ? 'required' : 'sometimes', 'array'],
            'attributes.*' => ['string', 'max:100'],
            'weight_grams' => ['nullable', 'integer', 'between:1,100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
