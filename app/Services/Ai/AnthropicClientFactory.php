<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AnthropicException;
use App\Models\User;

/**
 * Builds a user-scoped AnthropicClient by decrypting the caller's stored key.
 * Resolve this from the container (it's constructor-injectable) rather than
 * newing an AnthropicClient directly, so key retrieval and spend tracking are
 * wired consistently.
 */
class AnthropicClientFactory
{
    public function __construct(
        private readonly AnthropicKeyService $keys,
        private readonly SpendTracker $spend,
    ) {}

    /**
     * @throws AnthropicException when the user has no usable key on file.
     */
    public function forUser(User $user): AnthropicClient
    {
        $key = $this->keys->retrieve($user);

        if ($key === null) {
            throw AnthropicException::invalidKey('No Anthropic API key is set for this user.');
        }

        return new AnthropicClient($key, $user, $this->spend);
    }
}
