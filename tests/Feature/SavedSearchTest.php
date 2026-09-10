<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Enums\Tenure;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_saved_search(): void
    {
        $savedSearch = SavedSearch::factory()->create();

        $this->assertDatabaseHas('saved_searches', ['id' => $savedSearch->id]);
        $this->assertNull($savedSearch->min_price);
        $this->assertNull($savedSearch->property_type);
    }

    public function test_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $savedSearch = SavedSearch::factory()->for($user)->create();

        $this->assertTrue($savedSearch->user->is($user));
    }

    public function test_property_type_and_tenure_are_cast_to_enums(): void
    {
        $savedSearch = SavedSearch::factory()->create([
            'property_type' => PropertyType::Flat,
            'tenure' => Tenure::Leasehold,
        ]);

        $this->assertSame(PropertyType::Flat, $savedSearch->fresh()->property_type);
        $this->assertSame(Tenure::Leasehold, $savedSearch->fresh()->tenure);
    }
}
