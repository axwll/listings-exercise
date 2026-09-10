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
