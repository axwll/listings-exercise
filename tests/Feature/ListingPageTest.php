<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Models\Branch;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_only_live_listings(): void
    {
        $branch = Branch::factory()->create();
        Listing::factory(3)->live()->for($branch)->create();
        Listing::factory(2)->draft()->for($branch)->create();
        Listing::factory(1)->sold()->for($branch)->create();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Listings/Index')
                ->has('listings.data', 3)
            );
    }

    public function test_index_filters_by_property_type(): void
    {
        $branch = Branch::factory()->create();
        Listing::factory(2)->live()->for($branch)->create(['property_type' => PropertyType::Flat]);
        Listing::factory(4)->live()->for($branch)->create(['property_type' => PropertyType::Detached]);

        $this->get('/?property_type=flat&per_page=100')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('listings.data', 2));
    }

    public function test_index_filters_by_max_price(): void
    {
        Listing::factory()->live()->create(['price' => 200_000]);
        Listing::factory()->live()->create(['price' => 500_000]);

        $this->get('/?max_price=300000&per_page=100')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('listings.data', 1));
    }

    public function test_index_filters_by_region(): void
    {
        $wanted = Branch::factory()->create(['region' => 'Manchester']);
        $other = Branch::factory()->create(['region' => 'Leeds']);
        Listing::factory(2)->live()->for($wanted)->create();
        Listing::factory(3)->live()->for($other)->create();

        $this->get('/?region=Manchester&per_page=100')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('listings.data', 2));
    }

    /**
     * The filter form maps straight over these props, so they have to arrive as
     * flat arrays rather than wrapped in a resource envelope.
     */
    public function test_index_provides_flat_branch_and_property_type_props(): void
    {
        Branch::factory(3)->create();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('branches', 3, fn (AssertableInertia $branch) => $branch
                    ->hasAll('id', 'name', 'region')
                )
                ->has('propertyTypes', count(PropertyType::cases()), fn (AssertableInertia $type) => $type
                    ->hasAll('value', 'label')
                )
            );
    }

    public function test_index_passes_current_filters_back_to_the_page(): void
    {
        $this->get('/?max_price=300000&min_bedrooms=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.max_price', '300000')
                ->where('filters.min_bedrooms', '2')
            );
    }

    public function test_index_treats_blank_filters_as_absent(): void
    {
        Listing::factory(2)->live()->create();

        $this->get('/?max_price=&min_bedrooms=&property_type=&region=')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('listings.data', 2));
    }

    public function test_index_rejects_an_invalid_property_type(): void
    {
        $this->get('/?property_type=castle')
            ->assertSessionHasErrors('property_type');
    }

    public function test_index_rejects_a_price_above_the_cap(): void
    {
        $this->get('/?max_price=20000001')
            ->assertSessionHasErrors('max_price');
    }

    public function test_index_rejects_bedrooms_above_the_cap(): void
    {
        $this->get('/?min_bedrooms=11')
            ->assertSessionHasErrors('min_bedrooms');
    }

    public function test_index_paginates_deterministically_when_listed_at_ties(): void
    {
        $listedAt = now()->subDay();
        Listing::factory(6)->live()->create(['listed_at' => $listedAt]);

        $ids = fn (string $url) => collect(
            $this->get($url)->viewData('page')['props']['listings']['data']
        )->pluck('id')->all();

        $first = $ids('/?per_page=3&page=1');
        $second = $ids('/?per_page=3&page=2');

        $this->assertSame([], array_intersect($first, $second), 'Pages must not overlap.');
        $this->assertCount(6, array_unique([...$first, ...$second]));
    }

    public function test_show_renders_a_single_listing(): void
    {
        $listing = Listing::factory()->live()->create();

        $this->get("/listings/{$listing->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Listings/Show')
                ->where('listing.id', $listing->id)
                ->where('listing.reference', $listing->reference)
            );
    }

    /**
     * The index only exposes live listings, so show must not be a way around it.
     */
    #[DataProvider('nonLiveStates')]
    public function test_show_does_not_expose_non_live_listings(string $state): void
    {
        $listing = Listing::factory()->{$state}()->create();

        $this->get("/listings/{$listing->id}")->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLiveStates(): array
    {
        return [
            'draft' => ['draft'],
            'under offer' => ['underOffer'],
            'sold' => ['sold'],
        ];
    }

    public function test_the_demo_user_is_shared_with_every_page(): void
    {
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.email', 'demo@street.example')
            );
    }
}
