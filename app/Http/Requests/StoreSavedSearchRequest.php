<?php

namespace App\Http\Requests;

use App\Enums\PropertyType;
use App\Enums\Tenure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreSavedSearchRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:20000000'],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:20000000'],
            'min_bedrooms' => ['nullable', 'integer', 'min:0', 'max:10'],
            'max_bedrooms' => ['nullable', 'integer', 'min:0', 'max:10'],
            'min_bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'max_bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'property_type' => ['nullable', new Enum(PropertyType::class)],
            'region' => ['nullable', 'string', 'max:100'],
            'tenure' => ['nullable', new Enum(Tenure::class)],
        ];
    }

    /**
     * `gte:min_price`-style rules misbehave when the other field is absent
     * (every criterion here is independently optional), so range checks run
     * as a plain after-hook instead, and only when both sides are present.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ([
                ['min_price', 'max_price'],
                ['min_bedrooms', 'max_bedrooms'],
                ['min_bathrooms', 'max_bathrooms'],
            ] as [$min, $max]) {
                if ($this->filled($min) && $this->filled($max) && $this->integer($max) < $this->integer($min)) {
                    $validator->errors()->add($max, "The {$max} must be greater than or equal to {$min}.");
                }
            }
        });
    }
}
