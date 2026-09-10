<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Listing;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_alert(): void
    {
        $alert = Alert::factory()->create();

        $this->assertDatabaseHas('alerts', ['id' => $alert->id]);
    }

    public function test_belongs_to_a_user_and_a_listing(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->live()->create();
        $alert = Alert::factory()->for($user)->for($listing)->create();

        $this->assertTrue($alert->user->is($user));
        $this->assertTrue($alert->listing->is($listing));
    }

    public function test_links_to_saved_searches_via_the_pivot(): void
    {
        $alert = Alert::factory()->create();
        $savedSearch = SavedSearch::factory()->create();

        $alert->savedSearches()->attach($savedSearch);

        $this->assertTrue($alert->fresh()->savedSearches->contains($savedSearch));
    }

    public function test_one_alert_per_user_and_listing_is_enforced_at_the_database_level(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->live()->create();
        Alert::factory()->for($user)->for($listing)->create();

        $this->expectException(QueryException::class);

        Alert::factory()->for($user)->for($listing)->create();
    }
}
