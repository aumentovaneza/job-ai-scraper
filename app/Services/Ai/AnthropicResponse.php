<?php

namespace App\Services\Ai;

/**
 * A thin, immutable view over a successful /v1/messages response: the concatenated
 * text output, token usage, the resolved model, the estimated cost in cents (as
 * recorded on the AiCall ledger), and the raw decoded payload for callers that
 * need structured content blocks or tool use.
 */
class AnthropicResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
        public readonly array $raw,
    ) {}

    /**
     * Concatenate all text content blocks from a raw response payload.
     */
    public static function textFrom(array $raw): string
    {
        return collect($raw['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }
}
