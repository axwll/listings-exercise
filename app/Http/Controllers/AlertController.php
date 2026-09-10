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
