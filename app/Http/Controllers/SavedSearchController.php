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
