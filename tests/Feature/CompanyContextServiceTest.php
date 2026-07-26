<?php

use App\Models\CompanyContext;
use App\Services\CompanyContextService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.firecrawl.key', 'test-key');
    config()->set('services.firecrawl.base_url', 'https://api.firecrawl.dev');
});

/**
 * Fake Firecrawl `/scrape` so each page returns markdown keyed off the target
 * URL in the request body. Pages listed in $fail return an error response.
 *
 * @param  array<int, string>  $fail  target URLs that should fail
 */
function fakeFirecrawlScrape(array $fail = []): void
{
    Http::fake(function ($request) use ($fail) {
        $url = $request->data()['url'] ?? '';

        if (in_array($url, $fail, true)) {
            return Http::response(['error' => 'boom'], 500);
        }

        return Http::response([
            'success' => true,
            'data' => ['markdown' => "Facts from {$url}"],
        ], 200);
    });
}

it('scrapes homepage/about/careers and caches distilled facts', function () {
    fakeFirecrawlScrape();

    $facts = app(CompanyContextService::class)->factsFor('Acme Inc', 'https://acme.test/jobs/123');

    expect($facts)->toContain('Facts from https://acme.test/')
        ->toContain('Facts from https://acme.test/about')
        ->toContain('Facts from https://acme.test/careers');

    Http::assertSentCount(3);

    $row = CompanyContext::query()->where('company_key', 'acme inc')->first();
    expect($row)->not->toBeNull();
    expect($row->company)->toBe('Acme Inc');
    expect($row->source_urls)->toBe([
        'https://acme.test/',
        'https://acme.test/about',
        'https://acme.test/careers',
    ]);
    expect($row->fetched_at)->not->toBeNull();
    expect($row->isFresh())->toBeTrue();
});

it('returns fresh cached facts without scraping again', function () {
    fakeFirecrawlScrape();

    $row = CompanyContext::query()->create([
        'company_key' => 'acme inc',
        'company' => 'Acme Inc',
        'facts' => 'Cached facts about Acme.',
        'source_urls' => ['https://acme.test/'],
        'fetched_at' => now()->subDay(),
    ]);
    $fetchedAt = $row->fetched_at;

    $facts = app(CompanyContextService::class)->factsFor('Acme Inc', 'https://acme.test/jobs/123');

    expect($facts)->toBe('Cached facts about Acme.');
    Http::assertNothingSent();
    expect($row->fresh()->fetched_at->equalTo($fetchedAt))->toBeTrue();
});

it('re-scrapes when the cached row is stale', function () {
    fakeFirecrawlScrape();

    CompanyContext::query()->create([
        'company_key' => 'acme inc',
        'company' => 'Acme Inc',
        'facts' => 'Old facts.',
        'source_urls' => ['https://acme.test/'],
        'fetched_at' => now()->subDays(CompanyContext::FRESH_DAYS + 1),
    ]);

    $facts = app(CompanyContextService::class)->factsFor('Acme Inc', 'https://acme.test/jobs/123');

    expect($facts)->toContain('Facts from https://acme.test/');
    Http::assertSentCount(3);
});

it('returns null gracefully when Firecrawl is not configured', function () {
    config()->set('services.firecrawl.key', null);
    Http::fake();

    $facts = app(CompanyContextService::class)->factsFor('Acme Inc', 'https://acme.test/jobs/123');

    expect($facts)->toBeNull();
    Http::assertNothingSent();
});

it('returns null when no seed URL is available', function () {
    Http::fake();

    $facts = app(CompanyContextService::class)->factsFor('Acme Inc', null);

    expect($facts)->toBeNull();
    Http::assertNothingSent();
});

it('tolerates a per-page scrape failure and returns facts from the rest', function () {
    fakeFirecrawlScrape(fail: ['https://acme.test/about']);

    $facts = app(CompanyContextService::class)->factsFor('Acme Inc', 'https://acme.test/jobs/123');

    expect($facts)->toContain('Facts from https://acme.test/')
        ->toContain('Facts from https://acme.test/careers')
        ->not->toContain('Facts from https://acme.test/about');

    $row = CompanyContext::query()->where('company_key', 'acme inc')->first();
    expect($row->source_urls)->toBe([
        'https://acme.test/',
        'https://acme.test/careers',
    ]);
});
