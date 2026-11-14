<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Shared\I18n\Translations;
use Illuminate\Foundation\Http\FormRequest;

final class ProductRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return Translations::rules('title', $creating, false, 255)
            + Translations::rules('description', false, false, 20000)
            + [
                'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
                'brand' => ['nullable', 'string', 'max:150'],
                'attributes' => ['sometimes', 'array'],
                'weight_grams' => ['nullable', 'integer', 'between:1,100000'],
                'hs_code' => ['nullable', 'string', 'regex:/^\d{4,12}$/'],
                'country_of_origin' => ['nullable', 'string', 'size:2', 'alpha'],
            ];
    }
}
