<?php

namespace Tests\Feature;

use App\Actions\PublishListing;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Events\ListingWentLive;
use App\Models\Alert;
use App\Models\Listing;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PublishListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_the_listing_live_and_stamps_listed_at(): void
    {
        $listing = Listing::factory()->draft()->create();

        app(PublishListing::class)->handle($listing);

        $listing->refresh();
        $this->assertSame(ListingStatus::Live, $listing->status);
        $this->assertNotNull($listing->listed_at);
    }

    public function test_it_dispatches_listing_went_live(): void
    {
        Event::fake();
        $listing = Listing::factory()->draft()->create();

        app(PublishListing::class)->handle($listing);

        Event::assertDispatched(ListingWentLive::class, fn (ListingWentLive $event) => $event->listing->is($listing));
    }

    public function test_it_creates_an_alert_for_a_matching_saved_search(): void
    {
        $user = User::factory()->create();
        SavedSearch::factory()->for($user)->create(['max_price' => 300_000]);
        $listing = Listing::factory()->draft()->create(['price' => 250_000]);

        app(PublishListing::class)->handle($listing);

        $this->assertDatabaseHas('alerts', ['user_id' => $user->id, 'listing_id' => $listing->id]);
    }

    public function test_it_does_not_alert_a_non_matching_saved_search(): void
    {
        $user = User::factory()->create();
        SavedSearch::factory()->for($user)->create(['max_price' => 100_000]);
        $listing = Listing::factory()->draft()->create(['price' => 250_000]);

        app(PublishListing::class)->handle($listing);

        $this->assertDatabaseMissing('alerts', ['user_id' => $user->id, 'listing_id' => $listing->id]);
    }

    public function test_two_matching_saved_searches_for_the_same_user_produce_one_alert_linked_to_both(): void
    {
        $user = User::factory()->create();
        $byPrice = SavedSearch::factory()->for($user)->create(['max_price' => 300_000, 'name' => 'By price']);
        $byType = SavedSearch::factory()->for($user)->create(['property_type' => PropertyType::Flat, 'name' => 'By type']);
        $listing = Listing::factory()->draft()->create(['price' => 250_000, 'property_type' => PropertyType::Flat]);

        app(PublishListing::class)->handle($listing);

        $this->assertSame(1, Alert::query()->where('user_id', $user->id)->where('listing_id', $listing->id)->count());

        $alert = Alert::query()->where('user_id', $user->id)->where('listing_id', $listing->id)->sole();
        $this->assertCount(2, $alert->savedSearches);
        $this->assertTrue($alert->savedSearches->pluck('id')->contains($byPrice->id));
        $this->assertTrue($alert->savedSearches->pluck('id')->contains($byType->id));
    }

    public function test_matching_saved_searches_for_two_different_users_produce_one_alert_each(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        SavedSearch::factory()->for($userA)->create();
        SavedSearch::factory()->for($userB)->create();
        $listing = Listing::factory()->draft()->create();

        app(PublishListing::class)->handle($listing);

        $this->assertDatabaseHas('alerts', ['user_id' => $userA->id, 'listing_id' => $listing->id]);
        $this->assertDatabaseHas('alerts', ['user_id' => $userB->id, 'listing_id' => $listing->id]);
    }

    public function test_publishing_again_does_not_duplicate_the_alert(): void
    {
        $user = User::factory()->create();
        SavedSearch::factory()->for($user)->create();
        $listing = Listing::factory()->draft()->create();

        app(PublishListing::class)->handle($listing);
        app(PublishListing::class)->handle($listing->fresh());

        $this->assertSame(1, Alert::query()->where('user_id', $user->id)->where('listing_id', $listing->id)->count());
    }
}
