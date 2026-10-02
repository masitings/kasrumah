<?php

namespace App\Http\Controllers;

use App\Services\WeeklySummary;
use App\Support\SummaryCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SummaryController extends Controller
{
    public function index(Request $request, WeeklySummary $summary): Response
    {
        $cacheKey = SummaryCache::key($request->user());

        $data = Cache::remember(
            $cacheKey,
            now()->addDays(7),
            fn () => $summary->for($request->user())
        );

        return Inertia::render('kas/summary', [
            'summary' => $data,
        ]);
    }

    public function regenerate(Request $request, WeeklySummary $summary): Response
    {
        SummaryCache::forget($request->user());

        return $this->index($request, $summary);
    }
}
