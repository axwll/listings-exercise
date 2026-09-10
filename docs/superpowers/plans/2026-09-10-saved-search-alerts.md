# Saved-Search Alerts Implementation Plan

> **For agentic workers:** This plan is executed **inline, in the current session**, slice by slice — not via subagent-driven-development or executing-plans' default per-task commit flow. Each Task below ends with tests + `vendor/bin/pint --test` + `vendor/bin/phpstan analyse` passing, then **STOP and flag the slice for the user's review**. **Do not run `git commit` at any step, under any circumstances** — the user reviews and commits every slice themselves. This is a hard constraint, not a default.

**Goal:** Let a user create, view and delete saved searches, and be alerted (via a persisted `Alert` row) when a new listing goes live that matches one of their saved searches.

**Architecture:** Laravel 13 + Inertia + Vue 3, following the existing `Listing`/`Branch` conventions exactly (FormRequest validation, JsonResource shaping, Inertia page props, Tailwind components). Two new tables (`saved_searches`, `alerts`) plus a pivot (`alert_saved_search`). A single Eloquent scope, `Listing::matchingSavedSearch()`, is the one definition of "match" — used both for on-demand backfill (`SavedSearchController@show`) and for real-time alert creation (event listener on a new `ListingWentLive` event, dispatched by a new `PublishListing` action).

**Tech Stack:** PHP 8.4, Laravel 13.8, Inertia Laravel 3.3, Vue 3.5, Tailwind 4, PHPUnit 12, Pint (laravel preset), Larastan (Level 5).

**Spec:** [docs/plans/2026-09-10-feat-saved-searches.md](../../plans/2026-09-10-feat-saved-searches.md)

## Global Constraints

- PHP `^8.4`, Laravel `^13.8` — match `composer.json` exactly, no new dependencies.
- Auth is stubbed: build all user-scoping against `$request->user()` (inject `Illuminate\Http\Request`) — **not** the `auth()` helper, which returns `null` in this app since `ActAsDemoUser` only sets a resolver on the request, not the default session guard.
- `vendor/bin/pint --test` must pass (Laravel preset, `no_unused_imports` disabled) — no style diffs.
- `vendor/bin/phpstan analyse` must pass at Level 5 with an **empty** ignore list — new code must not add errors.
- `php artisan test` must pass in full after every slice, not just the new tests.
- `JsonResource::withoutWrapping()` is set globally (`AppServiceProvider`) — single resources have no `data` envelope; paginated collections keep `data`/`links`/`meta`.
- No JS test runner is configured (`package.json`'s `test` script is `php artisan test`) — Vue pages are verified via Inertia feature tests (component name + prop shape), matching `ListingPageTest`'s approach, not separate JS tests.
- **No commits.** Every slice ends with a stop-and-review checkpoint; the user commits.

---

## Known gap this plan fixes

The spec (`saved_searches.tenure`, a new `Tenure` enum) has no corresponding column on `listings` — confirmed by grep, there is no `tenure` anywhere in the codebase today. Without one, `tenure` could be saved on a search but could never match a listing. **Decision (confirmed with the user): add a nullable `tenure` column to `listings` as part of Slice 1**, alongside the new tables. This is folded into Slice 1 rather than given its own slice since it's schema/model groundwork, not a user-facing feature on its own.

---

### Task 1: Foundation — migrations, models, `Tenure` enum

**Files:**
- Create: `database/migrations/2026_09_10_120000_add_tenure_to_listings_table.php`
- Create: `database/migrations/2026_09_10_120100_create_saved_searches_table.php`
- Create: `database/migrations/2026_09_10_120200_create_alerts_table.php`
- Create: `database/migrations/2026_09_10_120300_create_alert_saved_search_table.php`
- Create: `app/Enums/Tenure.php`
- Create: `app/Models/SavedSearch.php`
- Create: `app/Models/Alert.php`
- Modify: `app/Models/Listing.php` — add `tenure` property/fillable/cast
- Modify: `app/Models/User.php` — add `savedSearches()`, `alerts()` relations
- Create: `database/factories/SavedSearchFactory.php`
- Create: `database/factories/AlertFactory.php`
- Modify: `database/factories/ListingFactory.php` — add `tenure` to `definition()`
- Create: `tests/Feature/SavedSearchTest.php`
- Create: `tests/Feature/AlertTest.php`
- Modify: `tests/Feature/ListingTest.php` — add a tenure-cast test

**Interfaces:**
- Produces: `App\Enums\Tenure` (backed string enum, cases `Freehold`/`Leasehold`/`ShareOfFreehold`, `label()`, `options(): list<array{value: string, label: string}>` — same shape as `PropertyType`). `App\Models\SavedSearch` (`user_id, name, min_price, max_price, min_bedrooms, max_bedrooms, min_bathrooms, max_bathrooms, property_type, region, tenure`; `user(): BelongsTo`, `alerts(): BelongsToMany`). `App\Models\Alert` (`user_id, listing_id`; `user(): BelongsTo`, `listing(): BelongsTo`, `savedSearches(): BelongsToMany`). `Listing::$tenure` (nullable `Tenure` cast). `User::savedSearches(): HasMany`, `User::alerts(): HasMany`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SavedSearchTest.php`:
```php
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
```

`tests/Feature/AlertTest.php`:
```php
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
```

Add to `tests/Feature/ListingTest.php` (inside the existing class, alongside `test_property_type_and_status_are_cast_to_enums`):
```php
    public function test_tenure_is_cast_to_enum(): void
    {
        $listing = Listing::factory()->live()->create(['tenure' => Tenure::Freehold]);

        $this->assertSame(Tenure::Freehold, $listing->fresh()->tenure);
    }
```
This needs `use App\Enums\Tenure;` added to that file's imports.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SavedSearchTest`
Expected: FAIL — `SavedSearch` class not found / `saved_searches` table not found.

Run: `php artisan test --filter=AlertTest`
Expected: FAIL — `Alert` class not found.

Run: `php artisan test --filter=ListingTest`
Expected: FAIL — `Undefined constant App\Enums\Tenure` / unknown column `tenure`.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_09_10_120000_add_tenure_to_listings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('tenure')->nullable()->after('property_type');
            $table->index('tenure');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['tenure']);
            $table->dropColumn('tenure');
        });
    }
};
```

`database/migrations/2026_09_10_120100_create_saved_searches_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('min_price')->nullable();
            $table->unsignedInteger('max_price')->nullable();
            $table->unsignedTinyInteger('min_bedrooms')->nullable();
            $table->unsignedTinyInteger('max_bedrooms')->nullable();
            $table->unsignedTinyInteger('min_bathrooms')->nullable();
            $table->unsignedTinyInteger('max_bathrooms')->nullable();
            $table->string('property_type')->nullable();
            $table->string('region')->nullable();
            $table->string('tenure')->nullable();
            $table->timestamps();

            // Looked up per-user on the index page, and scanned in full when a
            // listing goes live (see CreateAlertsForMatchingSavedSearches).
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
```

`database/migrations/2026_09_10_120200_create_alerts_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One alert per (user, listing) — enforced here, not just in
            // application code, since the reconciliation job described in the
            // spec needs this invariant to hold no matter what writes to it.
            $table->unique(['user_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
```

`database/migrations/2026_09_10_120300_create_alert_saved_search_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_saved_search', function (Blueprint $table) {
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_search_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_id', 'saved_search_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_saved_search');
    }
};
```

- [ ] **Step 4: Write the `Tenure` enum**

`app/Enums/Tenure.php`:
```php
<?php

namespace App\Enums;

enum Tenure: string
{
    case Freehold = 'freehold';
    case Leasehold = 'leasehold';
    case ShareOfFreehold = 'share_of_freehold';

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $tenure) => ['value' => $tenure->value, 'label' => $tenure->label()],
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Freehold => 'Freehold',
            self::Leasehold => 'Leasehold',
            self::ShareOfFreehold => 'Share of freehold',
        };
    }
}
```

- [ ] **Step 5: Write the models**

`app/Models/SavedSearch.php`:
```php
<?php

namespace App\Models;

use App\Enums\PropertyType;
use App\Enums\Tenure;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int|null $min_price
 * @property int|null $max_price
 * @property int|null $min_bedrooms
 * @property int|null $max_bedrooms
 * @property int|null $min_bathrooms
 * @property int|null $max_bathrooms
 * @property PropertyType|null $property_type
 * @property string|null $region
 * @property Tenure|null $tenure
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'min_price',
        'max_price',
        'min_bedrooms',
        'max_bedrooms',
        'min_bathrooms',
        'max_bathrooms',
        'property_type',
        'region',
        'tenure',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_price' => 'integer',
            'max_price' => 'integer',
            'min_bedrooms' => 'integer',
            'max_bedrooms' => 'integer',
            'min_bathrooms' => 'integer',
            'max_bathrooms' => 'integer',
            'property_type' => PropertyType::class,
            'tenure' => Tenure::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Alert, $this>
     */
    public function alerts(): BelongsToMany
    {
        return $this->belongsToMany(Alert::class, 'alert_saved_search');
    }
}
```

`app/Models/Alert.php`:
```php
<?php

namespace App\Models;

use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $listing_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Listing $listing
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SavedSearch> $savedSearches
 */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'listing_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsToMany<SavedSearch, $this>
     */
    public function savedSearches(): BelongsToMany
    {
        return $this->belongsToMany(SavedSearch::class, 'alert_saved_search');
    }
}
```

Modify `app/Models/Listing.php`:
- Add `use App\Enums\Tenure;` to the imports.
- Add `@property Tenure|null $tenure` to the class docblock, after the `@property PropertyType $property_type` line.
- Add `'tenure',` to `$fillable`, after `'property_type',`.
- Add `'tenure' => Tenure::class,` to `casts()`, after `'property_type' => PropertyType::class,`.

Modify `app/Models/User.php`:
- Add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the imports.
- Add after the `casts()` method:
```php
    /**
     * @return HasMany<SavedSearch, $this>
     */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class);
    }

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
```

- [ ] **Step 6: Write the factories**

`database/factories/SavedSearchFactory.php`:
```php
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
```

`database/factories/AlertFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'listing_id' => Listing::factory()->live(),
        ];
    }
}
```

Modify `database/factories/ListingFactory.php`:
- Add `use App\Enums\Tenure;` to the imports.
- Add `'tenure' => fake()->randomElement(Tenure::cases()),` to `definition()`, after the `'property_type'` line.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=SavedSearchTest`
Run: `php artisan test --filter=AlertTest`
Run: `php artisan test --filter=ListingTest`
Expected: all PASS.

- [ ] **Step 8: Run the full suite, Pint, and Larastan**

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```
Fix anything either tool flags (`vendor/bin/pint --repair` for style issues).

- [ ] **Step 9: STOP — flag for review**

Do not commit. Summarize what was added (migrations, models, enum, factories, tests) and wait for the user to review before starting Task 2.

---

### Task 2: Saved Searches — create, list, delete

**Files:**
- Create: `app/Http/Requests/StoreSavedSearchRequest.php`
- Create: `app/Http/Resources/SavedSearchResource.php`
- Create: `app/Http/Controllers/SavedSearchController.php` (`index`, `store`, `destroy` — `show` comes in Task 3)
- Modify: `routes/web.php` — add `saved-searches.index`, `.store`, `.destroy`
- Create: `resources/js/pages/SavedSearches/Index.vue`
- Modify: `resources/js/components/AppLayout.vue` — add a "Saved searches" nav link
- Create: `tests/Feature/SavedSearchPageTest.php`

**Interfaces:**
- Consumes: `SavedSearch` model + factory, `User::savedSearches()` (Task 1). `PropertyType::options()`, `Tenure::options()` (existing / Task 1).
- Produces: routes `saved-searches.index` (GET `/saved-searches`), `saved-searches.store` (POST `/saved-searches`), `saved-searches.destroy` (DELETE `/saved-searches/{savedSearch}`). `SavedSearchResource` — consumed by Task 3's `show()` and by the Vue pages.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SavedSearchPageTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SavedSearchPageTest`
Expected: FAIL — route `saved-searches.index` / controller not found (404s).

- [ ] **Step 3: Write the request, resource and controller**

`app/Http/Requests/StoreSavedSearchRequest.php`:
```php
<?php

namespace App\Http\Requests;

use App\Enums\PropertyType;
use App\Enums\Tenure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreSavedSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'min_bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'max_bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'min_bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'max_bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'property_type' => ['nullable', new Enum(PropertyType::class)],
            'region' => ['nullable', 'string', 'max:100'],
            'tenure' => ['nullable', new Enum(Tenure::class)],
        ];
    }

    /**
     * `gte:min_price`-style rules misbehave when the other field is absent
     * (every criterion here is independently optional), so range checks run
     * as a plain after-hook instead, and only when both sides are present.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ([
                ['min_price', 'max_price'],
                ['min_bedrooms', 'max_bedrooms'],
                ['min_bathrooms', 'max_bathrooms'],
            ] as [$min, $max]) {
                if ($this->filled($min) && $this->filled($max) && $this->integer($max) < $this->integer($min)) {
                    $validator->errors()->add($max, "The {$max} must be greater than or equal to {$min}.");
                }
            }
        });
    }
}
```

`app/Http/Resources/SavedSearchResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\SavedSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedSearch
 */
class SavedSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'min_bedrooms' => $this->min_bedrooms,
            'max_bedrooms' => $this->max_bedrooms,
            'min_bathrooms' => $this->min_bathrooms,
            'max_bathrooms' => $this->max_bathrooms,
            'property_type' => $this->property_type?->value,
            'property_type_label' => $this->property_type?->label(),
            'region' => $this->region,
            'tenure' => $this->tenure?->value,
            'tenure_label' => $this->tenure?->label(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

`app/Http/Controllers/SavedSearchController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\PropertyType;
use App\Enums\Tenure;
use App\Http\Requests\StoreSavedSearchRequest;
use App\Http\Resources\SavedSearchResource;
use App\Models\Branch;
use App\Models\SavedSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SavedSearchController extends Controller
{
    /**
     * List the current user's saved searches.
     */
    public function index(Request $request): Response
    {
        $savedSearches = $request->user()->savedSearches()->latest()->get();

        return Inertia::render('SavedSearches/Index', [
            'savedSearches' => SavedSearchResource::collection($savedSearches),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name', 'region']),
            'propertyTypes' => PropertyType::options(),
            'tenures' => Tenure::options(),
        ]);
    }

    /**
     * Save a new search for the current user.
     */
    public function store(StoreSavedSearchRequest $request): RedirectResponse
    {
        $request->user()->savedSearches()->create($request->validated());

        return redirect()->route('saved-searches.index');
    }

    /**
     * Scoped to the owner — not found (not forbidden) for anyone else's, so
     * existence isn't leaked.
     */
    public function destroy(Request $request, SavedSearch $savedSearch): RedirectResponse
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);

        $savedSearch->delete();

        return redirect()->route('saved-searches.index');
    }
}
```

**Note (discovered while implementing):** `auth()->user()` returns `null` in this app — `ActAsDemoUser` sets a resolver on the *request* only, not on the default session guard, so only `$request->user()` (matching `HandleInertiaRequests`'s existing usage) actually resolves the demo user. Every controller in this plan uses `$request->user()`, not the `auth()` helper — Task 3 and Task 5 below have been corrected accordingly.

Modify `routes/web.php`:
```php
use App\Http\Controllers\SavedSearchController;
// ...existing imports...

Route::get('/saved-searches', [SavedSearchController::class, 'index'])->name('saved-searches.index');
Route::post('/saved-searches', [SavedSearchController::class, 'store'])->name('saved-searches.store');
Route::delete('/saved-searches/{savedSearch}', [SavedSearchController::class, 'destroy'])->name('saved-searches.destroy');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SavedSearchPageTest`
Expected: PASS.

- [ ] **Step 5: Build the front end**

Modify `resources/js/components/AppLayout.vue` — replace the `<header>` block with:
```vue
        <header class="mb-8">
            <div class="flex items-center justify-between">
                <Link href="/" class="text-sm font-medium text-slate-500 transition hover:text-slate-900">
                    Street Listings
                </Link>
                <nav class="flex gap-4 text-sm font-medium text-slate-500">
                    <Link href="/saved-searches" class="transition hover:text-slate-900">Saved searches</Link>
                </nav>
            </div>
            <h1 class="mt-2 text-3xl font-bold tracking-tight">{{ heading }}</h1>
            <p v-if="subheading" class="mt-1 text-slate-500">{{ subheading }}</p>
        </header>
```

Create `resources/js/pages/SavedSearches/Index.vue`:
```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../components/AppLayout.vue';
import { formatPrice } from '../../format';

const props = defineProps({
    savedSearches: { type: Array, required: true },
    branches: { type: Array, required: true },
    propertyTypes: { type: Array, required: true },
    tenures: { type: Array, required: true },
});

const regions = computed(() => [...new Set(props.branches.map((b) => b.region))].sort());

const processing = ref(false);
const form = ref({
    name: '',
    min_price: '',
    max_price: '',
    min_bedrooms: '',
    max_bedrooms: '',
    min_bathrooms: '',
    max_bathrooms: '',
    property_type: '',
    region: '',
    tenure: '',
});

function create() {
    const payload = Object.fromEntries(
        Object.entries(form.value).filter(([, value]) => value !== ''),
    );

    router.post('/saved-searches', payload, {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => {
            form.value = {
                name: '', min_price: '', max_price: '', min_bedrooms: '', max_bedrooms: '',
                min_bathrooms: '', max_bathrooms: '', property_type: '', region: '', tenure: '',
            };
        },
    });
}

function destroy(savedSearch) {
    router.delete(`/saved-searches/${savedSearch.id}`);
}

function summarize(savedSearch) {
    const parts = [];
    if (savedSearch.min_bedrooms || savedSearch.max_bedrooms) {
        parts.push(`${savedSearch.min_bedrooms ?? 'any'}–${savedSearch.max_bedrooms ?? 'any'} bed`);
    }
    if (savedSearch.property_type_label) parts.push(savedSearch.property_type_label);
    if (savedSearch.tenure_label) parts.push(savedSearch.tenure_label);
    if (savedSearch.region) parts.push(savedSearch.region);
    if (savedSearch.max_price) parts.push(`under ${formatPrice(savedSearch.max_price)}`);
    return parts.length ? parts.join(' · ') : 'Any listing';
}

const fieldClasses =
    'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900';
</script>

<template>
    <Head title="Saved searches" />

    <AppLayout heading="Saved searches" subheading="Get alerted when a new listing matches.">
        <form class="mb-8 grid gap-3 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-3" @submit.prevent="create">
            <div class="flex flex-col gap-1 sm:col-span-3">
                <label for="name" class="text-xs font-medium text-slate-600">Name</label>
                <input id="name" v-model="form.name" type="text" required :class="fieldClasses" placeholder="e.g. 2-bed Chester under 300k" />
            </div>

            <div class="flex flex-col gap-1">
                <label for="max_price" class="text-xs font-medium text-slate-600">Max price (£)</label>
                <input id="max_price" v-model="form.max_price" type="number" min="0" :class="fieldClasses" />
            </div>

            <div class="flex flex-col gap-1">
                <label for="min_bedrooms" class="text-xs font-medium text-slate-600">Min beds</label>
                <input id="min_bedrooms" v-model="form.min_bedrooms" type="number" min="0" max="20" :class="fieldClasses" />
            </div>

            <div class="flex flex-col gap-1">
                <label for="property_type" class="text-xs font-medium text-slate-600">Type</label>
                <select id="property_type" v-model="form.property_type" :class="fieldClasses">
                    <option value="">Any type</option>
                    <option v-for="type in propertyTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="tenure" class="text-xs font-medium text-slate-600">Tenure</label>
                <select id="tenure" v-model="form.tenure" :class="fieldClasses">
                    <option value="">Any tenure</option>
                    <option v-for="tenure in tenures" :key="tenure.value" :value="tenure.value">{{ tenure.label }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="region" class="text-xs font-medium text-slate-600">Area</label>
                <select id="region" v-model="form.region" :class="fieldClasses">
                    <option value="">Any area</option>
                    <option v-for="region in regions" :key="region" :value="region">{{ region }}</option>
                </select>
            </div>

            <button type="submit" :disabled="processing" class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-50 sm:col-span-3 sm:w-fit">
                Save search
            </button>
        </form>

        <div v-if="savedSearches.length" class="grid gap-3">
            <div v-for="savedSearch in savedSearches" :key="savedSearch.id" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                <div>
                    <p class="font-medium text-slate-900">{{ savedSearch.name }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ summarize(savedSearch) }}</p>
                </div>
                <button type="button" class="text-sm text-slate-500 underline-offset-2 hover:underline" @click="destroy(savedSearch)">
                    Delete
                </button>
            </div>
        </div>
        <p v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500">
            No saved searches yet.
        </p>
    </AppLayout>
</template>
```

- [ ] **Step 6: Run the full suite, Pint, and Larastan**

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

- [ ] **Step 7: STOP — flag for review**

Do not commit. Summarize what was added and wait for the user to review before starting Task 3.

---

### Task 3: Matching scope + backfill (saved search "show")

**Files:**
- Modify: `app/Models/Listing.php` — add `scopeMatchingSavedSearch()`
- Modify: `app/Http/Controllers/SavedSearchController.php` — add `show()`
- Modify: `routes/web.php` — add `saved-searches.show`
- Create: `resources/js/pages/SavedSearches/Show.vue`
- Modify: `resources/js/pages/SavedSearches/Index.vue` — link each saved search's name to its show page
- Create: `tests/Feature/ListingMatchingSavedSearchTest.php`
- Modify: `tests/Feature/SavedSearchPageTest.php` — add `show()` tests

**Interfaces:**
- Consumes: `SavedSearch` (Task 1), `Listing::scopeLive()` (existing), `ListingResource` (existing).
- Produces: `Listing::matchingSavedSearch(SavedSearch $savedSearch)` query scope — this is the one definition of "match" reused by Task 4's alert listener. Route `saved-searches.show` (GET `/saved-searches/{savedSearch}`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ListingMatchingSavedSearchTest.php`:
```php
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
```

Add to `tests/Feature/SavedSearchPageTest.php`:
```php
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
```
This needs `use App\Models\Listing;` added to `SavedSearchPageTest`'s imports.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ListingMatchingSavedSearchTest`
Expected: FAIL — `matchingSavedSearch` scope doesn't exist.

Run: `php artisan test --filter=SavedSearchPageTest`
Expected: the three new tests FAIL (route not found); previously-passing tests still PASS.

- [ ] **Step 3: Write the scope**

Modify `app/Models/Listing.php` — add after `scopeLive()`, and add `use App\Models\SavedSearch;` to the imports:
```php
    /**
     * Listings satisfying every constraint on the given saved search. A null
     * field on the saved search means "no constraint" — the same semantics
     * ListingIndexRequest's optional filters already use.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopeMatchingSavedSearch(Builder $query, SavedSearch $savedSearch): void
    {
        $query
            ->when($savedSearch->min_price !== null, fn (Builder $q) => $q->where('price', '>=', $savedSearch->min_price))
            ->when($savedSearch->max_price !== null, fn (Builder $q) => $q->where('price', '<=', $savedSearch->max_price))
            ->when($savedSearch->min_bedrooms !== null, fn (Builder $q) => $q->where('bedrooms', '>=', $savedSearch->min_bedrooms))
            ->when($savedSearch->max_bedrooms !== null, fn (Builder $q) => $q->where('bedrooms', '<=', $savedSearch->max_bedrooms))
            ->when($savedSearch->min_bathrooms !== null, fn (Builder $q) => $q->where('bathrooms', '>=', $savedSearch->min_bathrooms))
            ->when($savedSearch->max_bathrooms !== null, fn (Builder $q) => $q->where('bathrooms', '<=', $savedSearch->max_bathrooms))
            ->when($savedSearch->property_type !== null, fn (Builder $q) => $q->where('property_type', $savedSearch->property_type))
            ->when($savedSearch->tenure !== null, fn (Builder $q) => $q->where('tenure', $savedSearch->tenure))
            ->when($savedSearch->region !== null, fn (Builder $q) => $q->whereHas(
                'branch',
                fn (Builder $branchQuery) => $branchQuery->where('region', $savedSearch->region)
            ));
    }
```

- [ ] **Step 4: Write the controller action and route**

Modify `app/Http/Controllers/SavedSearchController.php` — add imports `use App\Http\Resources\ListingResource;` and `use App\Models\Listing;`, then add after `index()`:
```php
    /**
     * Criteria plus live listings currently matching this saved search. Not
     * backfilled at save time — this always reflects "what matches right
     * now", which avoids a burst of alerts at save-time.
     */
    public function show(Request $request, SavedSearch $savedSearch): Response
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);

        $matches = Listing::query()
            ->live()
            ->matchingSavedSearch($savedSearch)
            ->latest('listed_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('SavedSearches/Show', [
            'savedSearch' => new SavedSearchResource($savedSearch),
            'matches' => ListingResource::collection($matches),
        ]);
    }
```

Modify `routes/web.php` — add:
```php
Route::get('/saved-searches/{savedSearch}', [SavedSearchController::class, 'show'])->name('saved-searches.show');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=ListingMatchingSavedSearchTest`
Run: `php artisan test --filter=SavedSearchPageTest`
Expected: all PASS.

- [ ] **Step 6: Build the front end**

Create `resources/js/pages/SavedSearches/Show.vue`:
```vue
<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../components/AppLayout.vue';
import ListingCard from '../../components/ListingCard.vue';
import Pagination from '../../components/Pagination.vue';

const props = defineProps({
    savedSearch: { type: Object, required: true },
    matches: { type: Object, required: true },
});
</script>

<template>
    <Head :title="savedSearch.name" />

    <AppLayout :heading="savedSearch.name" subheading="Live listings matching this search right now.">
        <div class="mb-6 flex flex-wrap gap-2 text-sm text-slate-600">
            <span v-if="savedSearch.min_bedrooms || savedSearch.max_bedrooms" class="rounded-full bg-slate-100 px-3 py-1">
                {{ savedSearch.min_bedrooms ?? 'any' }}–{{ savedSearch.max_bedrooms ?? 'any' }} bed
            </span>
            <span v-if="savedSearch.property_type_label" class="rounded-full bg-slate-100 px-3 py-1">{{ savedSearch.property_type_label }}</span>
            <span v-if="savedSearch.tenure_label" class="rounded-full bg-slate-100 px-3 py-1">{{ savedSearch.tenure_label }}</span>
            <span v-if="savedSearch.region" class="rounded-full bg-slate-100 px-3 py-1">{{ savedSearch.region }}</span>
            <span v-if="savedSearch.max_price" class="rounded-full bg-slate-100 px-3 py-1">under £{{ savedSearch.max_price.toLocaleString() }}</span>
        </div>

        <div v-if="matches.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <ListingCard v-for="listing in matches.data" :key="listing.id" :listing="listing" />
        </div>
        <p v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500">
            Nothing matches this search yet.
        </p>

        <Pagination :meta="matches.meta" :links="matches.links" />

        <Link href="/saved-searches" class="mt-6 inline-block text-sm text-slate-500 hover:text-slate-900">
            &larr; Back to saved searches
        </Link>
    </AppLayout>
</template>
```

Modify `resources/js/pages/SavedSearches/Index.vue` — wrap the saved search name in a link, changing:
```vue
                    <p class="font-medium text-slate-900">{{ savedSearch.name }}</p>
```
to:
```vue
                    <Link :href="`/saved-searches/${savedSearch.id}`" class="font-medium text-slate-900 hover:underline">
                        {{ savedSearch.name }}
                    </Link>
```
and add `Link` to the `@inertiajs/vue3` import at the top (`import { Head, Link, router } from '@inertiajs/vue3';`).

- [ ] **Step 7: Run the full suite, Pint, and Larastan**

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

- [ ] **Step 8: STOP — flag for review**

Do not commit. Summarize what was added and wait for the user to review before starting Task 4.

---

### Task 4: Publish trigger + alert creation

**Files:**
- Create: `app/Actions/PublishListing.php`
- Create: `app/Events/ListingWentLive.php`
- Create: `app/Listeners/CreateAlertsForMatchingSavedSearches.php`
- Create: `tests/Feature/PublishListingTest.php`

**Interfaces:**
- Consumes: `Listing::matchingSavedSearch()` (Task 3), `Alert`/`SavedSearch` models (Task 1).
- Produces: `app(PublishListing::class)->handle(Listing $listing): void` — the only way, in this codebase, that a listing becomes live and alerts get created. Tests call this directly, matching the spec's stated rationale (no publish route/UI exists yet; this is the same entry point a future admin action or import job would call).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PublishListingTest.php`:
```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PublishListingTest`
Expected: FAIL — `App\Actions\PublishListing` not found.

- [ ] **Step 3: Write the action, event and listener**

`app/Events/ListingWentLive.php`:
```php
<?php

namespace App\Events;

use App\Models\Listing;
use Illuminate\Foundation\Events\Dispatchable;

class ListingWentLive
{
    use Dispatchable;

    public function __construct(
        public readonly Listing $listing,
    ) {}
}
```

`app/Actions/PublishListing.php`:
```php
<?php

namespace App\Actions;

use App\Enums\ListingStatus;
use App\Events\ListingWentLive;
use App\Models\Listing;

/**
 * The single entry point for marking a listing live. Centralising this
 * (rather than e.g. an `isDirty('status')` model observer) means alerting is
 * a conscious, testable step here, not an inferred side effect of anything
 * that happens to write `status = live` — an observer would also silently
 * miss mass updates and seeder inserts, since no `updated` event fires for
 * those.
 */
class PublishListing
{
    public function handle(Listing $listing): void
    {
        $listing->update([
            'status' => ListingStatus::Live,
            'listed_at' => $listing->listed_at ?? now(),
        ]);

        ListingWentLive::dispatch($listing->fresh());
    }
}
```

`app/Listeners/CreateAlertsForMatchingSavedSearches.php`:
```php
<?php

namespace App\Listeners;

use App\Events\ListingWentLive;
use App\Models\Alert;
use App\Models\Listing;
use App\Models\SavedSearch;

/**
 * One Alert per (user, listing), regardless of how many of that user's saved
 * searches match — the alert_saved_search pivot records which one(s) did,
 * so that information isn't lost, but it isn't a reason to alert twice.
 */
class CreateAlertsForMatchingSavedSearches
{
    public function handle(ListingWentLive $event): void
    {
        $listing = $event->listing;

        $matchingSearches = SavedSearch::query()
            ->get()
            ->filter(fn (SavedSearch $savedSearch) => Listing::query()
                ->live()
                ->whereKey($listing->id)
                ->matchingSavedSearch($savedSearch)
                ->exists()
            );

        foreach ($matchingSearches->groupBy('user_id') as $userId => $searchesForUser) {
            $alert = Alert::query()->firstOrCreate([
                'user_id' => $userId,
                'listing_id' => $listing->id,
            ]);

            $alert->savedSearches()->syncWithoutDetaching($searchesForUser->pluck('id'));
        }
    }
}
```

No manual event registration is needed — Laravel auto-discovers listeners in `app/Listeners` whose `handle()` method is type-hinted with an event class. Verify with `php artisan event:list` after writing this (expect `App\Events\ListingWentLive` listed against `App\Listeners\CreateAlertsForMatchingSavedSearches@handle`).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=PublishListingTest`
Expected: PASS.

Also run: `php artisan event:list` and confirm the listener is registered as described above.

- [ ] **Step 5: Run the full suite, Pint, and Larastan**

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

- [ ] **Step 6: STOP — flag for review**

Do not commit. Summarize what was added and wait for the user to review before starting Task 5.

---

### Task 5: Alerts list

**Files:**
- Create: `app/Http/Resources/AlertResource.php`
- Create: `app/Http/Controllers/AlertController.php`
- Modify: `routes/web.php` — add `alerts.index`
- Create: `resources/js/pages/Alerts/Index.vue`
- Modify: `resources/js/components/AppLayout.vue` — add an "Alerts" nav link
- Create: `tests/Feature/AlertPageTest.php`

**Interfaces:**
- Consumes: `Alert` model + `User::alerts()` (Task 1), `ListingResource` (existing).
- Produces: route `alerts.index` (GET `/alerts`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AlertPageTest.php`:
```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=AlertPageTest`
Expected: FAIL — route `alerts.index` not found.

- [ ] **Step 3: Write the resource, controller and route**

`app/Http/Resources/AlertResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing' => new ListingResource($this->whenLoaded('listing')),
            'matched_saved_searches' => $this->whenLoaded(
                'savedSearches',
                fn () => $this->savedSearches->map(fn ($savedSearch) => [
                    'id' => $savedSearch->id,
                    'name' => $savedSearch->name,
                ])->all()
            ),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

`app/Http/Controllers/AlertController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlertResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    /**
     * List the current user's alerts, newest first, each annotated with the
     * saved search(es) that triggered it.
     */
    public function index(Request $request): Response
    {
        $alerts = $request->user()->alerts()
            ->with(['listing.branch', 'savedSearches'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Alerts/Index', [
            'alerts' => AlertResource::collection($alerts),
        ]);
    }
}
```

Modify `routes/web.php`:
```php
use App\Http\Controllers\AlertController;
// ...

Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=AlertPageTest`
Expected: PASS.

- [ ] **Step 5: Build the front end**

Modify `resources/js/components/AppLayout.vue` — add a second link inside the `<nav>` added in Task 2:
```vue
                <nav class="flex gap-4 text-sm font-medium text-slate-500">
                    <Link href="/saved-searches" class="transition hover:text-slate-900">Saved searches</Link>
                    <Link href="/alerts" class="transition hover:text-slate-900">Alerts</Link>
                </nav>
```

Create `resources/js/pages/Alerts/Index.vue`:
```vue
<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../components/AppLayout.vue';
import ListingCard from '../../components/ListingCard.vue';
import Pagination from '../../components/Pagination.vue';

defineProps({
    alerts: { type: Object, required: true },
});
</script>

<template>
    <Head title="Alerts" />

    <AppLayout heading="Alerts" subheading="New listings matching your saved searches.">
        <div v-if="alerts.data.length" class="grid gap-6">
            <div v-for="alert in alerts.data" :key="alert.id">
                <p class="mb-2 text-xs font-medium text-slate-500">
                    Matched: {{ alert.matched_saved_searches.map((s) => s.name).join(', ') }}
                </p>
                <ListingCard :listing="alert.listing" />
            </div>
        </div>
        <p v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500">
            No alerts yet.
        </p>

        <Pagination :meta="alerts.meta" :links="alerts.links" />
    </AppLayout>
</template>
```

- [ ] **Step 6: Run the full suite, Pint, and Larastan**

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

- [ ] **Step 7: STOP — flag for review**

Do not commit. Summarize what was added and wait for the user to review before starting Task 6.

---

### Task 6: Final polish — full suite pass + `NOTES.md`

**Files:**
- Create: `NOTES.md` (per `TASK.md`'s "What to hand back" — distinct from the untracked `notes.md` scratch file already in the repo, which is the user's own time-tracking and is left untouched)

**Interfaces:**
- Consumes: nothing new — this is a verification + documentation pass over everything built in Tasks 1–5.

- [ ] **Step 1: Run the full suite, Pint, and Larastan one more time**

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```
Fix anything outstanding.

- [ ] **Step 2: Manual smoke test (optional but recommended)**

```bash
npm run dev
php artisan serve
```
Visit `/saved-searches`, create a search, visit its show page, then in `php artisan tinker` run `app(\App\Actions\PublishListing::class)->handle(\App\Models\Listing::factory()->draft()->create(['price' => 200000]))` for a saved search with matching criteria, and confirm the alert appears at `/alerts`.

- [ ] **Step 3: Write `NOTES.md`**

Cover, per `TASK.md`'s ask (half a page): the key decisions and why (matching-scope-as-shared-primitive, one `Alert` per user/listing with the pivot recording which searches matched, backfill-as-live-query rather than backfilled rows at save time, an explicit `PublishListing` action over a model observer, and why), what was deliberately left out (search radius/geo-targeting, property features like garden/parking, alert frequency/digest options, real email delivery, the `alerts:reconcile` scheduled-command described but not built), and honest limitations — in particular: `CreateAlertsForMatchingSavedSearches` loads every saved search and runs one query per search against the newly-live listing, which is fine at this data volume but would need rethinking (indexing review, or a search-oriented store) as either listings or saved searches grow substantially.

Include the "AI usage" transparency note the spec calls for, matching what actually happened in this session: boilerplate generated and reviewed rather than hand-typed; design decisions (matching scope, dedup, backfill approach, publish action) made in the spec before implementation; a real gap in the spec (missing `tenure` column on `listings`) caught and resolved with the user before writing code; everything built slice-by-slice with tests, Pint and Larastan passing at each step, reviewed by the user before each commit.

- [ ] **Step 4: STOP — flag for final review**

Do not commit. This is the last slice — let the user review the whole feature before they commit and open their PR.
