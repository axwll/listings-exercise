# Implementation Plan — Saved-Search Alerts

## Summary

Buyers can save a search (a set of criteria) and receive alerts when a new
listing goes live that matches it. This document sets out the design
decisions, scope boundaries, and implementation order for the 3–4 hour
exercise, before writing any code.

## Domain model

```
saved_searches
  id
  user_id            FK -> users
  name               string   -- user-facing label, e.g. "2-bed Chester under 300k"
  min_price          nullable int
  max_price          nullable int
  min_bedrooms       nullable int
  max_bedrooms       nullable int
  min_bathrooms      nullable int
  max_bathrooms      nullable int
  property_type      nullable enum (PropertyType — existing)
  region             nullable string
  tenure             nullable enum (Tenure — new, mirrors PropertyType's shape)
  timestamps

alerts
  id
  user_id            FK -> users
  listing_id         FK -> listings
  timestamps

alert_saved_search   -- pivot
  alert_id           FK -> alerts
  saved_search_id    FK -> saved_searches
```

All criteria fields on `saved_searches` are nullable; a null field is treated
as "no constraint" during matching, consistent with how the existing
`ListingIndexRequest` filters already behave (all optional query params).

## Criteria — in scope vs deferred

**In scope:** price (min/max), bedrooms (min/max), bathrooms (min/max),
property type, region, tenure.

**Deferred to NOTES.md as future work:**

- **Search radius / geo-targeting** (area + radius instead of flat `region`
  string) — meaningfully more targeted for buyers, but real scope (geocoding,
  distance queries) beyond the time box.
- **Property features** (garden, parking, garage, etc.) — correct modelling
  is a `features` table + pivot + multi-select UI; a JSON array column would
  be cheaper but harder to query well. Either adds a sub-feature's worth of
  work on top of an already full slice, so it's deferred rather than
  half-built.

## Matching — the core design decision

A single Eloquent local scope on `Listing` is the one definition of "match"
in the system, used in both directions:

```php
Listing::live()->matchingSavedSearch($savedSearch)
```

- **Backfill** (`SavedSearchController@show`): run this scope directly to
  page through currently-live matches for a saved search.
- **New-listing alerting**: run the same scope per saved search when a
  listing goes live, to decide who to alert.

Having one shared primitive avoids the two call sites ever defining "match"
differently as the code evolves — a likely site for a bug if written twice.

## Trigger — new listing goes live

The app currently has no route or action that changes a listing's status;
`index`/`show` are the only endpoints. Considered a model observer
(`isDirty('status')`) first, but rejected it — it silently misses mass
updates and seeder inserts (no `updated()` event fires), and it infers
"went live" from a data change when nothing in the app currently performs
that as a deliberate action. Went with an explicit action instead, the one
missing piece the domain actually needs:

```php
// app/Actions/PublishListing.php
public function handle(Listing $listing): void
{
    $listing->update(['status' => ListingStatus::Live]);
    ListingWentLive::dispatch($listing->fresh());
}
```

- `CreateAlertsForMatchingSavedSearches` listener (unchanged from before):
    1. Loads saved searches whose criteria match the listing (via the scope
       above).
    2. Groups matches by `user_id`.
    3. For each user: one `Alert` row (user_id, listing_id), linked to _all_
       matching saved searches via the `alert_saved_search` pivot.

Since there's no publish route/UI yet, tests call
`app(PublishListing::class)->handle($listing)` directly — the same entry
point a future admin action or import job would call. One door in means no
ambiguity about mass-update or seeder bypass, and re-live behaviour becomes
a conscious, testable choice inside the action rather than an accident of
`isDirty`.

**Trade-off, for NOTES.md:** only fires when something calls
`PublishListing` — a future bulk-import path bypassing it would need
revisiting this. Accepted since no such path exists yet to defend against;
see Reconciliation below for the belt-and-braces answer.

## Reconciliation — nice-to-have

The action-based trigger has a known gap: anything that sets a listing to
`live` without calling `PublishListing` (a future bulk import, a
query-builder mass update, a seeder) produces no event and no alert — and
critically, fails silently, with nothing to indicate it happened.

Belt-and-braces answer, not built but specified: a scheduled command,

```
alerts:reconcile   (e.g. hourly)
```

For each live listing × each saved search matching it (the same
`matchingSavedSearch` scope used for backfill), check whether an `Alert`
already links that listing to that saved search via the pivot; if not,
create one. Keying existence on `(saved_search_id, listing_id)` rather than
`(user_id, listing_id)` keeps it idempotent and avoids re-alerting on a
listing that flaps `sold → live` repeatedly. Running it twice does nothing
extra on the second pass — it only ever fills genuine gaps.

Deliberately kept out of the core slice: it's a different piece of
infrastructure (scheduled command vs. event listener) from the rest of the
feature, and the brief only asks for a description of anything implying a
scheduled job, not for it to be operated.

## Duplicate handling

One `Alert` per (user, listing), regardless of how many saved searches it
matches. The `alert_saved_search` pivot records which search(es) triggered
it, so the alert view can show "matched: 2-bed Chester search, Any 3-bed
search" rather than losing that information. Chosen over a JSON column on
`alerts` for queryability and consistency with the rest of the schema.

## Backfill

In scope. On saving a search, the user is **not** proactively alerted about
already-live matches — no `Alert` rows are backfilled. Instead,
`GET /saved-searches/{savedSearch}` runs the same matching scope live and
shows current matches on demand. This avoids a burst of alerts at save-time
(support's stated concern about noise) while still answering "what matches
this right now" — which is arguably more useful to the user, since it's
always current rather than a snapshot from save-time.

## Alert frequency / delivery

Out of scope — the brief's phrasing ("alerted when a new listing matches")
reads as real-time by design, not a gap. Configurable frequency
(immediate/daily/weekly digest) is a natural extension, noted as future work
in NOTES.md, not built.

## Endpoints

| Method | Path                   | Notes                                                          |
| ------ | ---------------------- | -------------------------------------------------------------- |
| GET    | `/saved-searches`      | List current user's saved searches                             |
| GET    | `/saved-searches/{id}` | Criteria + live backfilled matches (paginated)                 |
| POST   | `/saved-searches`      | Create                                                         |
| DELETE | `/saved-searches/{id}` | Delete                                                         |
| GET    | `/alerts`              | List current user's alerts, each with matched saved search(es) |

All scoped to `auth()->user()` per the stubbed-auth pattern in the README.

New classes, following existing patterns (`ListingController`,
`ListingIndexRequest`, `ListingResource`):

- `SavedSearchController`, `AlertController`
- `StoreSavedSearchRequest`
- `SavedSearchResource`, `AlertResource`
- `SavedSearch`, `Alert` models
- `Tenure` enum

## Front end

Reuses existing components rather than introducing a new design system:

- `SavedSearches/Index.vue` — list + create form. Form fields follow the
  pattern in `ListingFilters.vue`.
- `SavedSearches/Show.vue` — criteria summary + matched listings, built the
  same way as `Listings/Index.vue` (`ListingCard`, `Pagination`).
- `Alerts/Index.vue` — list of alerts via `ListingCard`, annotated with
  which saved search(es) matched.

## Testing

- Feature tests for each `SavedSearchController` and `AlertController`
  endpoint (auth scoping, validation, correct shape).
- Unit tests for the `matchingSavedSearch` scope — each criterion in
  isolation and combined, including the "null criterion = no constraint"
  behaviour.
- A test asserting the dedup behaviour directly: a listing matching two
  saved searches for the same user produces exactly one `Alert` linked to
  both via the pivot.
- `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`
  run after each feature is added, not just at the end.
- Coverage driver + threshold: nice-to-have, only if time remains after the
  above.

## Implementation order

1. Migrations + models (`SavedSearch`, `Alert`, pivot, `Tenure` enum).
2. `matchingSavedSearch` scope + unit tests.
3. `PublishListing` action → `ListingWentLive` event → alert-creation
   listener + tests (including the dedup test).
4. `SavedSearchController` (index/show/store/destroy) + request + resource +
   feature tests.
5. `AlertController` (index) + resource + feature tests.
6. Vue pages, wired to the above.
7. Pint / Larastan / full test suite pass.
8. `NOTES.md` — decisions, trade-offs, deferred items (radius, property
   features, alert frequency), and honest limitations (e.g. how the
   `matchingSavedSearch` scope would need to change as listing volume grows
   — likely an index review, possibly a search-oriented store if it got
   large).

## AI usage (for transparency in the PR)

- Boilerplate (migrations, resource classes, enum scaffolding) generated
  and reviewed, not hand-typed.
- Design decisions (matching-scope-as-shared-primitive, dedup via pivot,
  backfill-as-live-query rather than backfilled rows, an explicit
  `PublishListing` action as trigger over an observer, and why) made
  deliberately before implementation, then handed to tooling to implement
  against this plan.
- Everything reviewed against existing codebase conventions
  (`ListingIndexRequest`, `ListingResource`, `ListingController`) before
  being accepted.
