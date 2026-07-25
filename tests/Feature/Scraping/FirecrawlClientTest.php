<?php

use App\Exceptions\FirecrawlException;
use App\Services\FirecrawlClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.firecrawl.key', 'test-key');
    config()->set('services.firecrawl.base_url', 'https://api.firecrawl.dev');
});

it('reports configured state based on the key', function () {
    config()->set('services.firecrawl.key', null);
    expect(app(FirecrawlClient::class)->configured())->toBeFalse();

    config()->set('services.firecrawl.key', 'k');
    expect(app(FirecrawlClient::class)->configured())->toBeTrue();
});

it('extracts structured data and returns the data payload', function () {
    Http::fake([
        'api.firecrawl.dev/v2/extract' => Http::response([
            'success' => true,
            'data' => ['jobs' => [['title' => 'Engineer']]],
        ]),
    ]);

    $data = app(FirecrawlClient::class)->extract(['https://acme.test/careers'], ['type' => 'object']);

    expect($data)->toBe(['jobs' => [['title' => 'Engineer']]]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['urls'] === ['https://acme.test/careers']);
});

it('throws when the key is missing', function () {
    config()->set('services.firecrawl.key', null);

    app(FirecrawlClient::class)->scrape('https://acme.test');
})->throws(FirecrawlException::class);

it('throws on an error response', function () {
    Http::fake(['api.firecrawl.dev/*' => Http::response(['error' => 'boom'], 500)]);

    app(FirecrawlClient::class)->scrape('https://acme.test');
})->throws(FirecrawlException::class);

it('throws on an unsuccessful payload', function () {
    Http::fake(['api.firecrawl.dev/*' => Http::response(['success' => false])]);

    app(FirecrawlClient::class)->scrape('https://acme.test');
})->throws(FirecrawlException::class);
