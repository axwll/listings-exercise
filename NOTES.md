# Notes: Saved-Search Alerts

## Time spent

- Project/environment setup: 15 min
- Plan: 45 min
- Implementation (Claude Code, reviewed in slices): 1 hr
- UX test / code review (pre-push): 20 min
- Final write-up: 10 min

## Key decisions

- **One matching definition.** `Listing::matchingSavedSearch()` is reused by both the on-demand backfill and the real-time alert listener, so "match" can't drift between the two.
- **One alert per (user, listing).** A pivot records which search(es) matched, DB-enforced via a unique constraint, not a reason to notify someone twice.
- **Backfill is a live query, not backfilled rows at save-time.** Avoids an alert burst on save (a real support concern) and stays current rather than a save-time snapshot.
- **Explicit `PublishListing` action, not a model observer.** An `isDirty('status')` observer would silently miss mass updates and seeder inserts. Nothing calls this action in the app yet, there's no publish UI, by design; tests call it directly, the same entry point a future admin action would use.

## Deliberately left out

Search radius/geo-targeting, property features (garden/parking), alert frequency/digest options, real email delivery, and an `alerts:reconcile` scheduled command (designed: hourly, idempotent on `(saved_search_id, listing_id)`, but not built). All out of scope for the time box, not gaps missed.

## Where this wouldn't hold up yet

- **Alert creation** is a linear scan over every saved search per publish event. At real volume this needs batching, an index review, or eventually a search-oriented store rather than a query per saved search.
- **Auth** is built entirely against the stubbed demo user, as instructed, so none of the saved-search/alert scoping has been tested against multiple real users or any permission boundary.
- **UI gap** Design has not been considered in this task given the time constraints. A real production project like this would have full UX plans and signoff prior to coding.

## Cloud architecture (not built, for discussion)

If this needed to actually run: Lambda (via Bref) if deploying fresh, low idle cost for a feature that's mostly event-driven and bursty rather than constant load. ECS instead if the org already runs one for other apps, rather than introduce a second compute pattern for this alone. RDS Postgres for the database, also the natural fit if radius search ever gets built, since PostGIS makes that far less painful. SQS to decouple alert-matching from the publish request, SES for actual delivery. S3 + CloudFront for built front-end assets. The reconciliation sweep maps onto EventBridge Scheduler triggering a scheduled Lambda invocation.

## AI usage

The design in this document came out of a planning conversation before any code was written: working through the brief section by section, questioning early assumptions (the initial publish trigger was a model observer, reasoned out of it once the gaps became clear), and pressure-testing scope decisions (criteria, backfill, dedup) before committing to them. That conversation is what ./docs/plan/ captures.

Built with Claude Code, iteratively, one vertical slice at a time (schema/models, CRUD, matching/backfill, publish trigger/alerting, alerts list, polish), test-first, full suite plus Pint and Larastan passing before each slice was reviewed and committed. Finally a full re-review of what had been created. Two real bugs were caught by that process before shipping: the missing `tenure` column, and `auth()->user()` silently returning null in this app's auth stub (only `$request->user()` is wired up). A further self-review pass after the feature was complete caught the issues listed above.