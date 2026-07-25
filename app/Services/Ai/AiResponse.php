<?php

namespace App\Services\Ai;

/**
 * A thin, immutable, provider-agnostic view over a successful chat completion:
 * the concatenated text output, token usage, the resolved model, the estimated
 * cost in cents (as recorded on the AiCall ledger), and the raw decoded payload
 * for callers that need structured content blocks or tool use.
 */
class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
        public readonly array $raw,
    ) {}
}
