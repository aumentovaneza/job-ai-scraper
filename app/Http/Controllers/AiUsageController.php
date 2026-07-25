<?php

namespace App\Http\Controllers;

use App\Services\Ai\SpendTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live per-user AI spend for the settings "spend this week" indicator (T-15).
 * Reads the ai_calls ledger via SpendTracker; scoped to the authenticated user.
 */
class AiUsageController extends Controller
{
    public function __construct(private readonly SpendTracker $spend) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'currency' => 'USD',
            'day' => [
                'spent_cents' => $this->spend->spentTodayCents($user),
                'cap_cents' => $user->daily_ai_spend_cap_cents,
            ],
            'week' => [
                'spent_cents' => $this->spend->spentThisWeekCents($user),
                'cap_cents' => $user->weekly_ai_spend_cap_cents,
            ],
        ]);
    }
}
