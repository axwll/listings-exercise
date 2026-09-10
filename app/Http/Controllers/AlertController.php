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
        // `id` is a tiebreaker: without it, alerts sharing a `created_at`
        // second can be ordered differently between page requests, which
        // duplicates or skips rows as you page through.
        $alerts = $request->user()->alerts()
            ->with(['listing.branch', 'savedSearches'])
            ->latest()
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Alerts/Index', [
            'alerts' => AlertResource::collection($alerts),
        ]);
    }
}
