<?php

namespace App\Services\Ai;

/**
 * The AI providers a user can bring a key for (BYOK). Each case owns the details
 * that differ per provider: the encrypted-key column, the verification-stamp
 * column, the config namespace under config('services.*'), and a display label.
 *
 * The backing value is what lands in ai_calls.provider and users.ai_provider, so
 * it must stay stable.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';

    /** The default provider for users who have not chosen one. */
    public static function default(): self
    {
        return self::Anthropic;
    }

    /** Resolve from a stored/user-supplied string, falling back to the default. */
    public static function fromNullable(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /** The users column holding this provider's encrypted key. */
    public function keyColumn(): string
    {
        return match ($this) {
            self::Anthropic => 'encrypted_anthropic_key',
            self::OpenAi => 'encrypted_openai_key',
        };
    }

    /** The users column stamping when this provider's key was last verified. */
    public function verifiedColumn(): string
    {
        return match ($this) {
            self::Anthropic => 'anthropic_key_verified_at',
            self::OpenAi => 'openai_key_verified_at',
        };
    }

    /** The config('services.*') namespace for this provider. */
    public function configKey(): string
    {
        return $this->value;
    }

    /** Read a value from this provider's services config block. */
    public function config(string $key, mixed $default = null): mixed
    {
        return config("services.{$this->value}.{$key}", $default);
    }

    /** Human-facing name for the SPA (Claude / ChatGPT). */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Claude',
            self::OpenAi => 'ChatGPT',
        };
    }
}
