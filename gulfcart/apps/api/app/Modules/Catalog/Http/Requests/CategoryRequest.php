<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Shared\I18n\Translations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $id = $this->route('category')?->id;

        return Translations::rules('name', $creating, $creating, 150) + [
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($id)],
            'parent_id' => ['nullable', 'uuid', 'exists:categories,id', Rule::notIn(array_filter([$id]))],
            'sort_order' => ['sometimes', 'integer', 'between:0,100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
