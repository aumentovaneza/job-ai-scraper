<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnthropicKeyRequest;
use App\Models\User;
use App\Services\Ai\AnthropicKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user BYOK management (T-10). The raw key is never returned — only a mask
 * and verification status. Every action operates on the authenticated user, so
 * one user can never read or mutate another's key.
 */
class AnthropicKeyController extends Controller
{
    public function __construct(private readonly AnthropicKeyService $keys) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['key' => $this->status($request->user())]);
    }

    /**
     * Store the key and immediately verify it with a 1-token ping so the user
     * gets inline feedback during onboarding.
     */
    public function store(StoreAnthropicKeyRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->keys->store($user, $request->validated('key'));
        $verified = $this->keys->verify($user);

        return response()->json([
            'verified' => $verified,
            'key' => $this->status($user->refresh()),
            'message' => $verified
                ? 'Key verified.'
                : 'The key was saved but could not be verified. Check that it is correct and active.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->keys->forget($request->user());

        return response()->json(['message' => 'Anthropic key removed.']);
    }

    /**
     * @return array{has_key: bool, masked: ?string, verified_at: ?string}
     */
    private function status(User $user): array
    {
        return [
            'has_key' => $this->keys->hasKey($user),
            'masked' => $this->keys->masked($user),
            'verified_at' => optional($user->anthropic_key_verified_at)?->toIso8601String(),
        ];
    }
}
