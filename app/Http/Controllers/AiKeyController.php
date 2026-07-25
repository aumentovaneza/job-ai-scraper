<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAiKeyRequest;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-user BYOK management for every provider (T-10). The raw key is never
 * returned — only a mask and verification status. Every action operates on the
 * authenticated user, so one user can never read or mutate another's key.
 */
class AiKeyController extends Controller
{
    public function __construct(private readonly AiKeyService $keys) {}

    public function show(Request $request): JsonResponse
    {
        $provider = $this->provider($request);

        return response()->json([
            'provider' => $provider->value,
            'key' => $this->keys->status($request->user(), $provider),
        ]);
    }

    /**
     * Store the key for a provider and immediately verify it with a 1-token ping
     * so the user gets inline feedback during onboarding.
     */
    public function store(StoreAiKeyRequest $request): JsonResponse
    {
        $user = $request->user();
        $provider = AiProvider::from($request->validated('provider'));

        $this->keys->store($user, $provider, $request->validated('key'));
        $verified = $this->keys->verify($user, $provider);

        return response()->json([
            'verified' => $verified,
            'provider' => $provider->value,
            'key' => $this->keys->status($user->refresh(), $provider),
            'message' => $verified
                ? 'Key verified.'
                : 'The key was saved but could not be verified. Check that it is correct and active.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $provider = $this->provider($request);
        $this->keys->forget($request->user(), $provider);

        return response()->json(['message' => $provider->label().' key removed.']);
    }

    /**
     * Resolve the target provider from the request, defaulting to the user's
     * active provider when none is supplied.
     */
    private function provider(Request $request): AiProvider
    {
        $value = $request->validate([
            'provider' => ['nullable', Rule::enum(AiProvider::class)],
        ])['provider'] ?? null;

        return $value !== null
            ? AiProvider::from($value)
            : $this->keys->activeProvider($request->user());
    }
}
