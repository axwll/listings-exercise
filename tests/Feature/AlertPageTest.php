<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AlertPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_current_users_alerts(): void
    {
        $demoUser = User::factory()->create();

        Alert::factory(2)->for($demoUser)->create();
        Alert::factory()->create(); // another user's

        $this->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Alerts/Index')
                ->has('alerts.data', 2)
            );
    }

    public function test_index_includes_the_matched_saved_searches(): void
    {
        $demoUser = User::factory()->create();
        $alert = Alert::factory()->for($demoUser)->create();
        $savedSearch = SavedSearch::factory()->for($demoUser)->create(['name' => '2-bed Chester']);
        $alert->savedSearches()->attach($savedSearch);

        $this->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alerts.data.0.matched_saved_searches.0.name', '2-bed Chester')
            );
    }

    public function test_index_includes_the_listing(): void
    {
        $demoUser = User::factory()->create();
        $alert = Alert::factory()->for($demoUser)->create();

        $this->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alerts.data.0.listing.id', $alert->listing_id)
            );
    }
}
