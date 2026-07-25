<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiException;
use App\Models\User;

/**
 * Builds a user-scoped AiClient for the user's active provider by decrypting the
 * caller's stored key. Resolve this from the container (it's constructor-
 * injectable) rather than newing a client directly, so provider selection, key
 * retrieval, and spend tracking are wired consistently.
 */
class AiClientFactory
{
    public function __construct(
        private readonly AiKeyService $keys,
        private readonly SpendTracker $spend,
    ) {}

    /**
     * A client for the user's active provider.
     *
     * @throws AiException when the user has no usable key for that provider.
     */
    public function forUser(User $user): AiClient
    {
        return $this->for($user, $this->keys->activeProvider($user));
    }

    /**
     * A client for a specific provider, regardless of the user's active choice.
     *
     * @throws AiException when the user has no usable key for that provider.
     */
    public function for(User $user, AiProvider $provider): AiClient
    {
        $key = $this->keys->retrieve($user, $provider);

        if ($key === null) {
            throw AiException::invalidKey("No {$provider->label()} API key is set for this user.");
        }

        return match ($provider) {
            AiProvider::Anthropic => new AnthropicClient($key, $user, $this->spend),
            AiProvider::OpenAi => new OpenAiClient($key, $user, $this->spend),
        };
    }
}
