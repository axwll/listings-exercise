<?php

namespace App\Http\Requests;

use App\Enums\PropertyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ListingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'property_type' => ['nullable', new Enum(PropertyType::class)],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:20000000'],
            'min_bedrooms' => ['nullable', 'integer', 'min:0', 'max:10'],
            'region' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
