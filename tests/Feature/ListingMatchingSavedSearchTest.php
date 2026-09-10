<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Enums\Tenure;
use App\Models\Branch;
use App\Models\Listing;
use App\Models\SavedSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingMatchingSavedSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_search_with_no_criteria_matches_everything(): void
    {
        Listing::factory(3)->live()->create();
        $savedSearch = SavedSearch::factory()->create();

        $this->assertCount(3, Listing::query()->matchingSavedSearch($savedSearch)->get());
    }

    public function test_matches_by_min_and_max_price(): void
    {
        Listing::factory()->live()->create(['price' => 100_000]);
        $inRange = Listing::factory()->live()->create(['price' => 250_000]);
        Listing::factory()->live()->create(['price' => 400_000]);

        $savedSearch = SavedSearch::factory()->create(['min_price' => 200_000, 'max_price' => 300_000]);

        $matches = Listing::query()->matchingSavedSearch($savedSearch)->get();

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($inRange));
    }

    public function test_matches_by_bedroom_range(): void
    {
        Listing::factory()->live()->create(['bedrooms' => 1]);
        $inRange = Listing::factory()->live()->create(['bedrooms' => 3]);
        Listing::factory()->live()->create(['bedrooms' => 5]);

        $savedSearch = SavedSearch::factory()->create(['min_bedrooms' => 2, 'max_bedrooms' => 4]);

        $matches = Listing::query()->matchingSavedSearch($savedSearch)->get();

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($inRange));
    }

    public function test_matches_by_bathroom_range(): void
    {
        Listing::factory()->live()->create(['bathrooms' => 1]);
        $inRange = Listing::factory()->live()->create(['bathrooms' => 2]);

        $savedSearch = SavedSearch::factory()->create(['min_bathrooms' => 2, 'max_bathrooms' => 3]);

        $matches = Listing::query()->matchingSavedSearch($savedSearch)->get();

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($inRange));
    }

    public function test_matches_by_property_type(): void
    {
        Listing::factory()->live()->create(['property_type' => PropertyType::Flat]);
        $match = Listing::factory()->live()->create(['property_type' => PropertyType::Detached]);

        $savedSearch = SavedSearch::factory()->create(['property_type' => PropertyType::Detached]);

        $matches = Listing::query()->matchingSavedSearch($savedSearch)->get();

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($match));
    }

    public function test_matches_by_tenure(): void
    {
        Listing::factory()->live()->create(['tenure' => Tenure::Leasehold]);
        $match = Listing::factory()->live()->create(['tenure' => Tenure::Freehold]);

        $savedSearch = SavedSearch::factory()->create(['tenure' => Tenure::Freehold]);

        $matches = Listing::query()->matchingSavedSearch($savedSearch)->get();

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($match));
    }

    public function test_matches_by_region_via_the_branch(): void
    {
        $wanted = Branch::factory()->create(['region' => 'Manchester']);
        $other = Branch::factory()->create(['region' => 'Leeds']);
        $match = Listing::factory()->live()->for($wanted)->create();
        Listing::factory()->live()->for($other)->create();

        $savedSearch = SavedSearch::factory()->create(['region' => 'Manchester']);

        $matches = Listing::query()->matchingSavedSearch($savedSearch)->get();

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($match));
    }

    public function test_combines_multiple_criteria(): void
    {
        $branch = Branch::factory()->create(['region' => 'Manchester']);
        $match = Listing::factory()->live()->for($branch)->create([
            'price' => 250_000,
            'bedrooms' => 3,
            'property_type' => PropertyType::Detached,
        ]);
        Listing::factory()->live()->for($branch)->create([
            'price' => 250_000,
            'bedrooms' => 3,
            'property_type' => PropertyType::Flat, // wrong type
        ]);

        $savedSearch = SavedSearch::factory()->create([
            'max_price' => 300_000,
            'min_bedrooms' => 2,
            'property_type' => PropertyType::Detached,
            'region' => 'Manchester',
        ]);

        $matches = Listing::query()->matchingSavedSearch($savedSearch)->get();

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($match));
    }
}
