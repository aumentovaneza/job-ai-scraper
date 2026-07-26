<?php

namespace App\Http\Controllers;

use App\Models\InsightSummary;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Insights dashboard data (T-60/T-61): live conversion analytics computed on the
 * fly from the application tracker, plus the most recent Claude-written narrative
 * summary (generated weekly by the digest worker). Scoped to the authenticated
 * user via AnalyticsService, which reads its own user_id-filtered queries.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $summary = InsightSummary::query()
            ->where('user_id', $user->id)
            ->latest('generated_at')
            ->first();

        return response()->json([
            'data' => $this->analytics->overviewFor($user),
            'summary' => $summary,
        ]);
    }
}
