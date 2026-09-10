<?php

namespace Database\Factories;

use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    protected $model = SavedSearch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'min_price' => null,
            'max_price' => null,
            'min_bedrooms' => null,
            'max_bedrooms' => null,
            'min_bathrooms' => null,
            'max_bathrooms' => null,
            'property_type' => null,
            'region' => null,
            'tenure' => null,
        ];
    }
}
