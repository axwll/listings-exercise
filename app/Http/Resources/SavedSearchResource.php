<?php

namespace App\Http\Resources;

use App\Models\SavedSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedSearch
 */
class SavedSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'min_bedrooms' => $this->min_bedrooms,
            'max_bedrooms' => $this->max_bedrooms,
            'min_bathrooms' => $this->min_bathrooms,
            'max_bathrooms' => $this->max_bathrooms,
            'property_type' => $this->property_type?->value,
            'property_type_label' => $this->property_type?->label(),
            'region' => $this->region,
            'tenure' => $this->tenure?->value,
            'tenure_label' => $this->tenure?->label(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
