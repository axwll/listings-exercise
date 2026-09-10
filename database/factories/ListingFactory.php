<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\Tenure;
use App\Models\Branch;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'reference' => 'STR-'.fake()->unique()->numerify('######'),
            'address_line_1' => fake()->buildingNumber().' '.fake()->streetName(),
            // A property is in the area its branch covers, so the city follows
            // the branch region rather than being drawn independently.
            'city' => fn (array $attributes) => Branch::findOrFail($attributes['branch_id'])->region,
            'postcode' => fake()->postcode(),
            'price' => fake()->numberBetween(120, 950) * 1000,
            'bedrooms' => fake()->numberBetween(1, 6),
            'bathrooms' => fake()->numberBetween(1, 4),
            'property_type' => fake()->randomElement(PropertyType::cases()),
            'tenure' => fake()->randomElement(Tenure::cases()),
            'status' => ListingStatus::Draft,
            'listed_at' => null,
        ];
    }

    /**
     * The factory default, stated explicitly for tests that want to say so.
     */
    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Draft,
            'listed_at' => null,
        ]);
    }

    public function live(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Live,
            'listed_at' => fake()->dateTimeBetween('-3 months', 'now'),
        ]);
    }

    public function underOffer(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::UnderOffer,
            'listed_at' => fake()->dateTimeBetween('-6 months', '-1 month'),
        ]);
    }

    public function sold(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Sold,
            'listed_at' => fake()->dateTimeBetween('-1 year', '-3 months'),
        ]);
    }
}
