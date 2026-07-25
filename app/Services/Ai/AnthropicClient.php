<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiException;
use App\Models\AiCall;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * User-scoped wrapper around the Anthropic Messages API (T-11, PLAN.md §7).
 *
 * Every instance is bound to exactly one user's key. It:
 *   - enforces that user's daily/weekly spend caps *before* the call,
 *   - retries transient failures (429 / 5xx / connection errors) with backoff,
 *   - records every attempt — success and error — to the ai_calls ledger,
 *   - throws AiException on non-recoverable failures (e.g. invalid key).
 *
 * Construct via AiClientFactory so the key is pulled and decrypted once. The key
 * is held in memory only and never logged.
 */
class AnthropicClient implements AiClient
{
    /** HTTP statuses worth retrying (rate limit, overload, transient server errors). */
    private const RETRYABLE = [429, 500, 502, 503, 504, 529];

    public function __construct(
        private readonly string $apiKey,
        private readonly User $user,
        private readonly SpendTracker $spend,
    ) {}

    public function messages(
        array $payload,
        string $purpose,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $promptVersion = null,
    ): AiResponse {
        // Pre-call gate: block before any request leaves the app.
        $this->spend->assertWithinCaps($this->user);

        $model = $payload['model'] ??= config('services.anthropic.model');

        [$response, $connectionError] = $this->sendWithRetry($payload);

        if ($connectionError !== null) {
            $this->record($model, $purpose, $promptVersion, 0, 0, 0, 'error', $connectionError->getMessage(), $referenceType, $referenceId);

            throw new AiException(
                'Could not reach the Anthropic API: '.$connectionError->getMessage(),
                previous: $connectionError,
            );
        }

        if (! $response->successful()) {
            $message = $this->errorMessage($response);
            $this->record($model, $purpose, $promptVersion, 0, 0, 0, 'error', $message, $referenceType, $referenceId);

            if (in_array($response->status(), [401, 403], true)) {
                throw AiException::invalidKey($message);
            }

            throw new AiException($message, status: $response->status());
        }

        $raw = $response->json();
        $inputTokens = (int) data_get($raw, 'usage.input_tokens', 0);
        $outputTokens = (int) data_get($raw, 'usage.output_tokens', 0);
        $resolvedModel = data_get($raw, 'model', $model);
        $costCents = $this->estimateCostCents($resolvedModel, $inputTokens, $outputTokens);

        $this->record(
            $resolvedModel, $purpose, $promptVersion, $inputTokens, $outputTokens, $costCents,
            'ok', null, $referenceType, $referenceId,
        );

        return new AiResponse(
            text: $this->textFrom($raw),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costCents: $costCents,
            model: $resolvedModel,
            raw: $raw,
        );
    }

    /**
     * Concatenate all text content blocks from a raw Messages API payload.
     */
    private function textFrom(array $raw): string
    {
        return collect($raw['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }

    /**
     * @return array{0: ?Response, 1: ?ConnectionException}
     */
    private function sendWithRetry(array $payload): array
    {
        $maxRetries = (int) config('services.anthropic.max_retries');
        $baseMs = (int) config('services.anthropic.retry_base_ms');

        $attempt = 0;

        while (true) {
            try {
                $response = Http::baseUrl(config('services.anthropic.base_url'))
                    ->timeout(config('services.anthropic.timeout'))
                    ->withHeaders([
                        'x-api-key' => $this->apiKey,
                        'anthropic-version' => config('services.anthropic.version'),
                    ])
                    ->post('/v1/messages', $payload);

                if (! in_array($response->status(), self::RETRYABLE, true) || $attempt >= $maxRetries) {
                    return [$response, null];
                }
            } catch (ConnectionException $e) {
                if ($attempt >= $maxRetries) {
                    return [null, $e];
                }
            }

            // Exponential backoff (skipped in the sync test queue via sleep(0)).
            usleep($baseMs * (2 ** $attempt) * 1000);
            $attempt++;
        }
    }

    private function estimateCostCents(string $model, int $inputTokens, int $outputTokens): int
    {
        $pricing = config("services.anthropic.pricing.$model");

        if (! is_array($pricing)) {
            return 0;
        }

        return (int) round(
            $inputTokens / 1_000_000 * $pricing['input']
            + $outputTokens / 1_000_000 * $pricing['output']
        );
    }

    private function errorMessage(Response $response): string
    {
        return (string) (data_get($response->json(), 'error.message')
            ?: 'Anthropic API returned HTTP '.$response->status().'.');
    }

    private function record(
        string $model,
        string $purpose,
        ?string $promptVersion,
        int $inputTokens,
        int $outputTokens,
        int $costCents,
        string $status,
        ?string $error,
        ?string $referenceType,
        ?int $referenceId,
    ): void {
        AiCall::create([
            'user_id' => $this->user->id,   // explicit — workers aren't authenticated
            'provider' => 'anthropic',
            'model' => $model,
            'endpoint' => 'messages',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_cents' => $costCents,
            'purpose' => $purpose,
            'prompt_version' => $promptVersion,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'status' => $status,
            'error' => $error,
        ]);
    }
}
