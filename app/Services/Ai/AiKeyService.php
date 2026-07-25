<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Per-user BYOK key management for every AI provider (T-10, PLAN.md §7).
 *
 * Each provider's key lives in its own users column (see AiProvider), encrypted
 * at rest with Laravel Crypt, never returned via the API (only ever surfaced
 * masked), and never logged. verify() confirms a key is live with a 1-token ping
 * and stamps the provider's verified-at column so the UI can surface breakage.
 */
class AiKeyService
{
    /**
     * Encrypt and persist a user's key for a provider. Storing a (possibly new)
     * key resets that provider's verification stamp — it isn't trusted until
     * verify() succeeds.
     */
    public function store(User $user, AiProvider $provider, string $key): void
    {
        $user->forceFill([
            $provider->keyColumn() => Crypt::encryptString(trim($key)),
            $provider->verifiedColumn() => null,
        ])->save();
    }

    /**
     * Decrypt the stored key for a provider, or null if none is set (or it can no
     * longer be decrypted, e.g. after an APP_KEY rotation).
     */
    public function retrieve(User $user, AiProvider $provider): ?string
    {
        $ciphertext = $user->{$provider->keyColumn()};

        if (empty($ciphertext)) {
            return null;
        }

        try {
            return Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasKey(User $user, AiProvider $provider): bool
    {
        return ! empty($user->{$provider->keyColumn()});
    }

    /**
     * Remove a provider's key and its verification stamp.
     */
    public function forget(User $user, AiProvider $provider): void
    {
        $user->forceFill([
            $provider->keyColumn() => null,
            $provider->verifiedColumn() => null,
        ])->save();
    }

    /**
     * A display-safe mask (e.g. "sk-ant-a…c123"), or null if no key is set.
     * Never reveals the middle of the key.
     */
    public function masked(User $user, AiProvider $provider): ?string
    {
        $key = $this->retrieve($user, $provider);

        if ($key === null || $key === '') {
            return null;
        }

        if (strlen($key) <= 12) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 8).'…'.substr($key, -4);
    }

    /**
     * The provider-scoped status the SPA renders: whether a key is set, its mask,
     * and when it was last verified.
     *
     * @return array{has_key: bool, masked: ?string, verified_at: ?string}
     */
    public function status(User $user, AiProvider $provider): array
    {
        return [
            'has_key' => $this->hasKey($user, $provider),
            'masked' => $this->masked($user, $provider),
            'verified_at' => optional($user->{$provider->verifiedColumn()})?->toIso8601String(),
        ];
    }

    /**
     * Whether the given provider's key is verified for this user.
     */
    public function isVerified(User $user, AiProvider $provider): bool
    {
        return $user->{$provider->verifiedColumn()} !== null;
    }

    /**
     * The user's active AI provider (what their work runs against).
     */
    public function activeProvider(User $user): AiProvider
    {
        return AiProvider::fromNullable($user->ai_provider);
    }

    /**
     * Switch the user's active AI provider.
     */
    public function setActiveProvider(User $user, AiProvider $provider): void
    {
        $user->forceFill(['ai_provider' => $provider->value])->save();
    }

    /**
     * Ping the provider with a 1-token request to confirm the stored key is live.
     * On success, stamps the verified-at column; on any failure, clears it.
     * Returns whether the key is valid. Never throws — the caller shows the UI.
     */
    public function verify(User $user, AiProvider $provider): bool
    {
        $key = $this->retrieve($user, $provider);

        if ($key === null) {
            $user->forceFill([$provider->verifiedColumn() => null])->save();

            return false;
        }

        try {
            $response = match ($provider) {
                AiProvider::Anthropic => $this->pingAnthropic($key),
                AiProvider::OpenAi => $this->pingOpenAi($key),
            };
        } catch (Throwable) {
            // Network failure — can't confirm the key. Leave it unverified.
            $user->forceFill([$provider->verifiedColumn() => null])->save();

            return false;
        }

        $valid = $response->successful();

        $user->forceFill([
            $provider->verifiedColumn() => $valid ? now() : null,
        ])->save();

        return $valid;
    }

    private function pingAnthropic(string $key)
    {
        return Http::baseUrl(config('services.anthropic.base_url'))
            ->timeout(config('services.anthropic.timeout'))
            ->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => config('services.anthropic.version'),
            ])
            ->post('/v1/messages', [
                'model' => config('services.anthropic.verify_model'),
                'max_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);
    }

    private function pingOpenAi(string $key)
    {
        return Http::baseUrl(config('services.openai.base_url'))
            ->timeout(config('services.openai.timeout'))
            ->withToken($key)
            ->post('/v1/chat/completions', [
                'model' => config('services.openai.verify_model'),
                'max_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);
    }
}
