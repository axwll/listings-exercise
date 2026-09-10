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
