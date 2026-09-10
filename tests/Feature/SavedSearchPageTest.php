<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Models\Branch;
use App\Models\Listing;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SavedSearchPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_current_users_saved_searches(): void
    {
        $this->seed();
        $demoUser = User::query()->orderBy('id')->first();

        SavedSearch::factory(2)->for($demoUser)->create();
        SavedSearch::factory()->create(); // another user's

        $this->get('/saved-searches')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SavedSearches/Index')
                ->has('savedSearches', 2)
            );
    }

    public function test_index_provides_property_type_and_tenure_options(): void
    {
        $this->seed();

        $this->get('/saved-searches')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('propertyTypes', count(PropertyType::cases()))
                ->has('tenures', 3)
            );
    }

    public function test_store_creates_a_saved_search_for_the_current_user(): void
    {
        $this->seed();
        $demoUser = User::query()->orderBy('id')->first();

        $this->post('/saved-searches', [
            'name' => '2-bed Chester under 300k',
            'max_price' => 300000,
            'min_bedrooms' => 2,
        ])->assertRedirect('/saved-searches');

        $this->assertDatabaseHas('saved_searches', [
            'user_id' => $demoUser->id,
            'name' => '2-bed Chester under 300k',
            'max_price' => 300000,
            'min_bedrooms' => 2,
        ]);
    }

    public function test_store_requires_a_name(): void
    {
        $this->seed();

        $this->post('/saved-searches', ['max_price' => 300000])
            ->assertSessionHasErrors('name');
    }

    public function test_store_rejects_a_max_price_below_min_price(): void
    {
        $this->seed();

        $this->post('/saved-searches', [
            'name' => 'Bad range',
            'min_price' => 300000,
            'max_price' => 200000,
        ])->assertSessionHasErrors('max_price');
    }

    public function test_store_rejects_an_invalid_property_type(): void
    {
        $this->seed();

        $this->post('/saved-searches', [
            'name' => 'Invalid type',
            'property_type' => 'castle',
        ])->assertSessionHasErrors('property_type');
    }

    public function test_store_rejects_a_price_above_the_cap(): void
    {
        $this->seed();

        $this->post('/saved-searches', [
            'name' => 'Too expensive',
            'max_price' => 20_000_001,
        ])->assertSessionHasErrors('max_price');
    }

    public function test_store_rejects_bedrooms_above_the_cap(): void
    {
        $this->seed();

        $this->post('/saved-searches', [
            'name' => 'Too many beds',
            'max_bedrooms' => 11,
        ])->assertSessionHasErrors('max_bedrooms');
    }

    public function test_destroy_deletes_the_current_users_saved_search(): void
    {
        $this->seed();
        $demoUser = User::query()->orderBy('id')->first();
        $savedSearch = SavedSearch::factory()->for($demoUser)->create();

        $this->delete("/saved-searches/{$savedSearch->id}")
            ->assertRedirect('/saved-searches');

        $this->assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    }

    public function test_destroy_cannot_delete_another_users_saved_search(): void
    {
        $this->seed();
        $savedSearch = SavedSearch::factory()->create(); // not the demo user

        $this->delete("/saved-searches/{$savedSearch->id}")->assertNotFound();

        $this->assertDatabaseHas('saved_searches', ['id' => $savedSearch->id]);
    }

    public function test_show_renders_criteria_and_current_matches(): void
    {
        // Deliberately not $this->seed() — the seeder's ~190 random-priced
        // listings would make a "count" assertion here flaky (some would
        // coincidentally fall under max_price too).
        $demoUser = User::factory()->create();
        Listing::factory(2)->live()->create(['price' => 150_000]);
        $savedSearch = SavedSearch::factory()->for($demoUser)->create(['max_price' => 200_000]);

        $this->get("/saved-searches/{$savedSearch->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SavedSearches/Show')
                ->where('savedSearch.id', $savedSearch->id)
                ->has('matches.data', 2)
            );
    }

    public function test_show_only_returns_live_matches(): void
    {
        $demoUser = User::factory()->create();
        Listing::factory()->draft()->create(['price' => 150_000]);
        $savedSearch = SavedSearch::factory()->for($demoUser)->create();

        $this->get("/saved-searches/{$savedSearch->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('matches.data', 0));
    }

    public function test_show_is_not_found_for_another_users_saved_search(): void
    {
        $this->seed();
        $savedSearch = SavedSearch::factory()->create(); // not the demo user

        $this->get("/saved-searches/{$savedSearch->id}")->assertNotFound();
    }

    /**
     * ListingResource unconditionally accesses $this->branch, so a matches
     * query that doesn't eager-load it lazy-loads branch once per row.
     */
    public function test_show_eager_loads_branch_to_avoid_n_plus_one(): void
    {
        $demoUser = User::factory()->create();
        $branches = Branch::factory(10)->create();
        foreach ($branches as $branch) {
            Listing::factory()->live()->for($branch)->create(['price' => 150_000]);
        }
        $savedSearch = SavedSearch::factory()->for($demoUser)->create(['max_price' => 200_000]);

        DB::enableQueryLog();
        $this->get("/saved-searches/{$savedSearch->id}")->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Without eager-loading: 1 (saved search binding) + 1 (matches page)
        // + 10 (one lazy-loaded branch per row) = 12. With it: ~4.
        $this->assertLessThan(8, $queryCount, 'Expected branch to be eager-loaded, not lazy-loaded per listing.');
    }

    public function test_index_fails_gracefully_when_no_user_is_seeded(): void
    {
        // No $this->seed(), no User::factory() — simulates a freshly
        // migrated but unseeded database, where the demo-auth stub resolves
        // no user at all.
        $this->get('/saved-searches')->assertStatus(503);
    }
}
