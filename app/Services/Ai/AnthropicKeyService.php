<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Per-user BYOK key management (T-10, PLAN.md §7).
 *
 * The Anthropic key is encrypted at rest with Laravel Crypt, never returned via
 * the API (only ever surfaced masked), and never logged. verify() confirms a key
 * is live with a 1-token ping and stamps users.anthropic_key_verified_at so the
 * UI can surface breakage.
 */
class AnthropicKeyService
{
    /**
     * Encrypt and persist a user's key. Storing a (possibly new) key resets the
     * verification stamp — it isn't trusted until verify() succeeds.
     */
    public function store(User $user, string $key): void
    {
        $user->forceFill([
            'encrypted_anthropic_key' => Crypt::encryptString(trim($key)),
            'anthropic_key_verified_at' => null,
        ])->save();
    }

    /**
     * Decrypt the stored key, or null if none is set (or it can no longer be
     * decrypted, e.g. after an APP_KEY rotation).
     */
    public function retrieve(User $user): ?string
    {
        if (empty($user->encrypted_anthropic_key)) {
            return null;
        }

        try {
            return Crypt::decryptString($user->encrypted_anthropic_key);
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasKey(User $user): bool
    {
        return ! empty($user->encrypted_anthropic_key);
    }

    /**
     * Remove the key and its verification stamp.
     */
    public function forget(User $user): void
    {
        $user->forceFill([
            'encrypted_anthropic_key' => null,
            'anthropic_key_verified_at' => null,
        ])->save();
    }

    /**
     * A display-safe mask (e.g. "sk-ant-a…c123"), or null if no key is set.
     * Never reveals the middle of the key.
     */
    public function masked(User $user): ?string
    {
        $key = $this->retrieve($user);

        if ($key === null || $key === '') {
            return null;
        }

        if (strlen($key) <= 12) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 8).'…'.substr($key, -4);
    }

    /**
     * Ping Claude with a 1-token request to confirm the stored key is live.
     * On success, stamps anthropic_key_verified_at; on any failure, clears it.
     * Returns whether the key is valid. Never throws — the caller shows the UI.
     */
    public function verify(User $user): bool
    {
        $key = $this->retrieve($user);

        if ($key === null) {
            $user->forceFill(['anthropic_key_verified_at' => null])->save();

            return false;
        }

        try {
            $response = Http::baseUrl(config('services.anthropic.base_url'))
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
        } catch (Throwable) {
            // Network failure — can't confirm the key. Leave it unverified.
            $user->forceFill(['anthropic_key_verified_at' => null])->save();

            return false;
        }

        $valid = $response->successful();

        $user->forceFill([
            'anthropic_key_verified_at' => $valid ? now() : null,
        ])->save();

        return $valid;
    }
}
