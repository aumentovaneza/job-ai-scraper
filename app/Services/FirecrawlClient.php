<?php

namespace App\Services;

use App\Exceptions\FirecrawlException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP wrapper over the Firecrawl API (T-20). Exposes the two endpoints
 * the scraping pipeline needs — `/scrape` (single page → markdown/html) and
 * `/extract` (pages → structured JSON against a schema) — with retry/backoff,
 * a bounded timeout, and structured logging on failure. Project-level key from
 * `config/services.php` (not BYOK).
 *
 * All failures surface as FirecrawlException so callers (queued jobs, the
 * synchronous test-scrape endpoint) have a single error type to handle.
 */
class FirecrawlClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $baseUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    public function configured(): bool
    {
        return filled($this->key());
    }

    /**
     * Scrape a single URL. Returns Firecrawl's `data` payload (markdown, html,
     * metadata, …).
     *
     * @return array<string, mixed>
     */
    public function scrape(string $url, array $options = []): array
    {
        return $this->post('/v2/scrape', array_merge([
            'url' => $url,
            'formats' => ['markdown', 'html'],
            'onlyMainContent' => true,
        ], $options));
    }

    /**
     * Extract structured data from one or more URLs against a JSON schema.
     *
     * @param  array<int, string>  $urls
     * @param  array<string, mixed>  $schema  JSON Schema describing the output
     * @return array<string, mixed>
     */
    public function extract(array $urls, array $schema, ?string $prompt = null): array
    {
        $payload = [
            'urls' => array_values($urls),
            'schema' => $schema,
        ];

        if (filled($prompt)) {
            $payload['prompt'] = $prompt;
        }

        return $this->post('/v2/extract', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $path, array $payload): array
    {
        if (! $this->configured()) {
            throw new FirecrawlException('Firecrawl API key is not configured.');
        }

        try {
            $response = $this->request()->post($path, $payload);
        } catch (Throwable $e) {
            Log::error('Firecrawl request failed', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            throw new FirecrawlException("Firecrawl request to {$path} failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            Log::error('Firecrawl returned an error response', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new FirecrawlException("Firecrawl {$path} returned HTTP {$response->status()}.");
        }

        $body = $response->json();

        if (! is_array($body) || (array_key_exists('success', $body) && $body['success'] === false)) {
            throw new FirecrawlException("Firecrawl {$path} returned an unsuccessful payload.");
        }

        return $body['data'] ?? $body;
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
        return $this->apiKey ?? config('services.firecrawl.key');
    }

    protected function url(): string
    {
        return rtrim($this->baseUrl ?? config('services.firecrawl.base_url', 'https://api.firecrawl.dev'), '/');
    }

    protected function timeout(): int
    {
        return $this->timeout ?? (int) config('services.firecrawl.timeout', 60);
    }
}
