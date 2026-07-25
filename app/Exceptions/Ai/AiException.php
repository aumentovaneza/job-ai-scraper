<?php

namespace App\Exceptions\Ai;

use RuntimeException;
use Throwable;

/**
 * Raised when a call to an AI provider (Anthropic or OpenAI) fails in a way the
 * caller must handle: an invalid/expired key, a non-retryable client error, or an
 * exhausted retry budget on transient failures. Carries the HTTP status when one
 * is available so callers can distinguish auth failures (401/403) from the rest.
 */
class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $invalidKey = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidKey(string $message = 'The API key was rejected.'): self
    {
        return new self($message, status: 401, invalidKey: true);
    }
}
