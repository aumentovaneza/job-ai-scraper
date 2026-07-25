<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiException;
use App\Models\AiCall;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * User-scoped wrapper around the OpenAI Chat Completions API — the ChatGPT
 * alternative to AnthropicClient. Same responsibilities and guarantees:
 *   - enforces the user's daily/weekly spend caps *before* the call,
 *   - retries transient failures (429 / 5xx / connection errors) with backoff,
 *   - records every attempt — success and error — to the ai_calls ledger,
 *   - throws AiException on non-recoverable failures (e.g. invalid key).
 *
 * The provider-agnostic $payload ({messages, max_tokens}) is accepted verbatim —
 * OpenAI's request/response shape differs from Anthropic's, so the wire-format
 * translation lives here (Bearer auth, choices[].message.content, prompt/
 * completion token usage).
 */
class OpenAiClient implements AiClient
{
    /** HTTP statuses worth retrying (rate limit, transient server errors). */
    private const RETRYABLE = [429, 500, 502, 503, 504];

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

        $model = $payload['model'] ??= config('services.openai.model');

        [$response, $connectionError] = $this->sendWithRetry($payload);

        if ($connectionError !== null) {
            $this->record($model, $purpose, $promptVersion, 0, 0, 0, 'error', $connectionError->getMessage(), $referenceType, $referenceId);

            throw new AiException(
                'Could not reach the OpenAI API: '.$connectionError->getMessage(),
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
        $inputTokens = (int) data_get($raw, 'usage.prompt_tokens', 0);
        $outputTokens = (int) data_get($raw, 'usage.completion_tokens', 0);
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
     * Concatenate the assistant text across choices from a Chat Completions payload.
     */
    private function textFrom(array $raw): string
    {
        return collect($raw['choices'] ?? [])
            ->map(fn ($choice) => (string) data_get($choice, 'message.content', ''))
            ->implode('');
    }

    /**
     * @return array{0: ?Response, 1: ?ConnectionException}
     */
    private function sendWithRetry(array $payload): array
    {
        $maxRetries = (int) config('services.openai.max_retries');
        $baseMs = (int) config('services.openai.retry_base_ms');

        $attempt = 0;

        while (true) {
            try {
                $response = Http::baseUrl(config('services.openai.base_url'))
                    ->timeout(config('services.openai.timeout'))
                    ->withToken($this->apiKey)
                    ->post('/v1/chat/completions', $payload);

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
        $pricing = config("services.openai.pricing.$model");

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
            ?: 'OpenAI API returned HTTP '.$response->status().'.');
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
            'provider' => 'openai',
            'model' => $model,
            'endpoint' => 'chat.completions',
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
