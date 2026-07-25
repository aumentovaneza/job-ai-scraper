<?php

namespace App\Services;

use App\Exceptions\VoyageException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wrapper over the Voyage AI embeddings endpoint (T-25). Owner-funded project
 * key (not BYOK). Returns float vectors sized to match the pgvector column
 * (`config('services.voyage.dimensions')`, default 1024).
 *
 * Failures surface as VoyageException so the embedding job can retry/fail
 * cleanly without poisoning the dedup pipeline.
 */
class VoyageClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $model = null,
        private readonly ?int $dimensions = null,
        private readonly ?int $timeout = null,
    ) {}

    public function configured(): bool
    {
        return filled($this->key());
    }

    public function dimensions(): int
    {
        return $this->dimensions ?? (int) config('services.voyage.dimensions', 1024);
    }

    /**
     * Embed a single piece of text and return its vector.
     *
     * @return array<int, float>
     */
    public function embed(string $input, string $inputType = 'document'): array
    {
        return $this->embedBatch([$input], $inputType)[0];
    }

    /**
     * Embed a batch of texts. Preserves input order.
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $inputs, string $inputType = 'document'): array
    {
        if (! $this->configured()) {
            throw new VoyageException('Voyage API key is not configured.');
        }

        if ($inputs === []) {
            return [];
        }

        $payload = [
            'input' => array_values($inputs),
            'model' => $this->model(),
            'input_type' => $inputType,
            'output_dimension' => $this->dimensions(),
        ];

        try {
            $response = $this->request()->post('/embeddings', $payload);
        } catch (Throwable $e) {
            Log::error('Voyage request failed', ['exception' => $e->getMessage()]);

            throw new VoyageException("Voyage embeddings request failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            Log::error('Voyage returned an error response', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new VoyageException("Voyage embeddings returned HTTP {$response->status()}.");
        }

        $data = $response->json('data');

        if (! is_array($data) || $data === []) {
            throw new VoyageException('Voyage embeddings returned no data.');
        }

        // The API returns objects with an `index` — order by it defensively.
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            static fn (array $row): array => array_map('floatval', $row['embedding'] ?? []),
            $data,
        );
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->url())
            ->withToken($this->key())
            ->acceptJson()
            ->timeout($this->timeout())
            ->retry(3, 500, throw: false);
    }

    protected function key(): ?string
    {
        return $this->apiKey ?? config('services.voyage.key');
    }

    protected function url(): string
    {
        return rtrim($this->baseUrl ?? config('services.voyage.base_url', 'https://api.voyageai.com/v1'), '/');
    }

    protected function model(): string
    {
        return $this->model ?? (string) config('services.voyage.model', 'voyage-3-lite');
    }

    protected function timeout(): int
    {
        return $this->timeout ?? (int) config('services.voyage.timeout', 30);
    }
}
