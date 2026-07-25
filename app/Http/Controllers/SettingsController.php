<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateSettingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Account settings the user manages directly: timezone and AI spend caps (T-15).
 * Always operates on the authenticated user.
 */
class SettingsController extends Controller
{
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return response()->json([
            'settings' => [
                'timezone' => $user->timezone,
                'daily_ai_spend_cap_cents' => $user->daily_ai_spend_cap_cents,
                'weekly_ai_spend_cap_cents' => $user->weekly_ai_spend_cap_cents,
            ],
        ]);
    }
}
