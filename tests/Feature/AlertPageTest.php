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

    /**
     * An alert is a historical record — deleting the saved search that
     * generated it shouldn't make the alert disappear too. The pivot row
     * does cascade-delete, so matched_saved_searches becomes empty; the
     * front end is responsible for not rendering that as a blank "Matched:".
     */
    public function test_index_still_shows_an_alert_after_its_matched_saved_search_is_deleted(): void
    {
        $demoUser = User::factory()->create();
        $alert = Alert::factory()->for($demoUser)->create();
        $savedSearch = SavedSearch::factory()->for($demoUser)->create();
        $alert->savedSearches()->attach($savedSearch);

        $savedSearch->delete();

        $this->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.matched_saved_searches', [])
            );
    }

    /**
     * `id` is a tiebreaker: without it, alerts sharing a `created_at` second
     * can be ordered differently between page requests, duplicating or
     * skipping rows as you page through — the same issue ListingController's
     * index already guards against.
     */
    public function test_index_paginates_deterministically_when_created_at_ties(): void
    {
        $demoUser = User::factory()->create();
        $createdAt = now()->subDay();
        Alert::factory(6)->for($demoUser)->create(['created_at' => $createdAt]);

        $ids = fn (string $url) => collect(
            $this->get($url)->viewData('page')['props']['alerts']['data']
        )->pluck('id')->all();

        $first = $ids('/alerts?per_page=3&page=1');
        $second = $ids('/alerts?per_page=3&page=2');

        $this->assertSame([], array_intersect($first, $second), 'Pages must not overlap.');
        $this->assertCount(6, array_unique([...$first, ...$second]));
    }

    public function test_index_fails_gracefully_when_no_user_is_seeded(): void
    {
        // No $this->seed(), no User::factory() — simulates a freshly
        // migrated but unseeded database, where the demo-auth stub resolves
        // no user at all.
        $this->get('/alerts')->assertStatus(503);
    }
}
