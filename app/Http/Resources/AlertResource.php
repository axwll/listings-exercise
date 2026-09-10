<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing' => new ListingResource($this->whenLoaded('listing')),
            'matched_saved_searches' => $this->whenLoaded(
                'savedSearches',
                fn () => $this->savedSearches->map(fn ($savedSearch) => [
                    'id' => $savedSearch->id,
                    'name' => $savedSearch->name,
                ])->all()
            ),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
