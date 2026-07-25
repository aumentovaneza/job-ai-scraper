<?php

namespace App\Services\Ai;

/**
 * A user-scoped chat client for one AI provider. Implementations enforce the
 * user's spend caps, retry transient failures, record every attempt to the
 * ai_calls ledger, and throw AiException on non-recoverable failures.
 *
 * Resolve concrete clients via AiClientFactory so the right provider and key are
 * wired for the user.
 */
interface AiClient
{
    /**
     * Send a chat request on behalf of this client's user.
     *
     * @param  array  $payload  Provider-agnostic body. Recognised keys:
     *                          `messages` (array of {role, content}) and
     *                          `max_tokens` (int). `model` defaults to the
     *                          provider's configured work model when absent.
     * @param  string  $purpose  ai_calls.purpose tag (e.g. 'voice_profile').
     */
    public function messages(
        array $payload,
        string $purpose,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): AiResponse;
}
