<?php

use App\Models\JobSource;
use App\Models\User;
use App\Services\JsonApi\JsonApiScraper;
use Illuminate\Support\Facades\Http;

/** Build an unsaved json_api source with the given url + config. */
function jsonApiSource(string $url, array $config): JobSource
{
    return JobSource::factory()->make([
        'user_id' => User::factory()->create()->id,
        'type' => 'json_api',
        'url' => $url,
        'config' => $config,
    ]);
}

it('parses a top-level array (RemoteOK shape), skipping the legal-notice row and 0 salaries', function () {
    Http::fake([
        'remoteok.com/*' => Http::response([
            ['legal' => 'RemoteOK legal notice — no position/company here'],
            [
                'position' => 'Senior Go Engineer',
                'company' => 'Acme',
                'location' => 'Remote',
                'salary_min' => 0,
                'salary_max' => 120000,
                'description' => '<p>Write Go.</p>',
                'url' => 'https://remoteok.com/l/1',
                'date' => '2026-06-01T00:00:00Z',
                'tags' => ['golang', 'backend'],
            ],
        ]),
    ]);

    $jobs = app(JsonApiScraper::class)->scrape(jsonApiSource('https://remoteok.com/api', [
        'items_path' => null,
        'field_map' => [
            'title' => 'position',
            'company' => 'company',
            'location' => 'location',
            'remote_type' => 'location',
            'salary_min' => 'salary_min',
            'salary_max' => 'salary_max',
            'jd_text' => 'description',
            'apply_url' => 'url',
            'posted_at' => 'date',
            'tags' => 'tags',
        ],
    ]));

    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->title)->toBe('Senior Go Engineer');
    expect($jobs[0]->company)->toBe('Acme');
    expect($jobs[0]->remoteType)->toBe('remote');
    expect($jobs[0]->salaryMin)->toBeNull(); // 0 → unknown
    expect($jobs[0]->salaryMax)->toBe(120000);
    expect($jobs[0]->jdText)->toBe('Write Go.');
    expect($jobs[0]->applyUrl)->toBe('https://remoteok.com/l/1');
    expect($jobs[0]->tags)->toBe(['golang', 'backend']);
});

it('parses a nested items_path and a freeform salary string (Remotive shape)', function () {
    Http::fake([
        'remotive.com/*' => Http::response([
            'jobs' => [[
                'title' => 'Backend Engineer',
                'company_name' => 'Globex',
                'candidate_required_location' => 'Anywhere',
                'salary' => '$100k - $120k',
                'description' => 'Ship APIs.',
                'url' => 'https://remotive.com/1',
                'publication_date' => '2026-05-01',
                'tags' => ['python'],
            ]],
        ]),
    ]);

    $jobs = app(JsonApiScraper::class)->scrape(jsonApiSource('https://remotive.com/api/remote-jobs', [
        'items_path' => 'jobs',
        'field_map' => [
            'title' => 'title',
            'company' => 'company_name',
            'location' => 'candidate_required_location',
            'salary' => 'salary',
            'jd_text' => 'description',
            'apply_url' => 'url',
            'posted_at' => 'publication_date',
            'tags' => 'tags',
        ],
    ]));

    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->remoteType)->toBe('remote'); // inferred from "Anywhere"
    expect($jobs[0]->salaryMin)->toBe(100000);
    expect($jobs[0]->salaryMax)->toBe(120000);
    expect($jobs[0]->salaryCurrency)->toBe('USD');
});

it('parses uppercase K/M salary suffixes', function () {
    Http::fake([
        'example.test/*' => Http::response([
            'jobs' => [[
                'title' => 'Engineer',
                'company_name' => 'Acme',
                'salary' => '€90K – €1.2M',
            ]],
        ]),
    ]);

    $jobs = app(JsonApiScraper::class)->scrape(jsonApiSource('https://example.test/api', [
        'items_path' => 'jobs',
        'field_map' => ['title' => 'title', 'company' => 'company_name', 'salary' => 'salary'],
    ]));

    expect($jobs[0]->salaryMin)->toBe(90000);
    expect($jobs[0]->salaryMax)->toBe(1200000);
    expect($jobs[0]->salaryCurrency)->toBe('EUR');
});

it('maps a boolean remote flag and epoch timestamp (Arbeitnow shape)', function () {
    Http::fake([
        'arbeitnow.com/*' => Http::response([
            'data' => [
                [
                    'title' => 'Onsite Role',
                    'company_name' => 'Initech',
                    'location' => 'Berlin',
                    'remote' => false,
                    'created_at' => 1_717_000_000,
                    'url' => 'https://arbeitnow.com/1',
                ],
                [
                    'title' => 'Remote Role',
                    'company_name' => 'Initech',
                    'location' => 'Berlin',
                    'remote' => true,
                    'created_at' => 1_717_000_000,
                    'url' => 'https://arbeitnow.com/2',
                ],
            ],
        ]),
    ]);

    $jobs = app(JsonApiScraper::class)->scrape(jsonApiSource('https://www.arbeitnow.com/api/job-board-api', [
        'items_path' => 'data',
        'field_map' => [
            'title' => 'title',
            'company' => 'company_name',
            'location' => 'location',
            'remote_type' => 'remote',
            'posted_at' => 'created_at',
            'apply_url' => 'url',
        ],
    ]));

    expect($jobs)->toHaveCount(2);
    expect($jobs[0]->remoteType)->toBeNull();      // remote:false → null, not onsite
    expect($jobs[1]->remoteType)->toBe('remote');  // remote:true → remote
    expect($jobs[0]->postedAt)->not->toBeNull();
});

it('coerces tags from an array of objects via a wildcard path', function () {
    Http::fake([
        'example.test/*' => Http::response([
            'jobs' => [[
                'title' => 'Engineer',
                'company_name' => 'Acme',
                'tags' => [['name' => 'go'], ['name' => 'k8s'], ['name' => 'go']],
            ]],
        ]),
    ]);

    $jobs = app(JsonApiScraper::class)->scrape(jsonApiSource('https://example.test/api', [
        'items_path' => 'jobs',
        'field_map' => [
            'title' => 'title',
            'company' => 'company_name',
            'tags' => 'tags.*.name',
        ],
    ]));

    expect($jobs[0]->tags)->toBe(['go', 'k8s']); // stringified + deduped
});

it('skips items missing a company', function () {
    Http::fake([
        'example.test/*' => Http::response([
            'jobs' => [
                ['title' => 'No Company Role'],
                ['title' => 'Real Role', 'company_name' => 'Acme'],
            ],
        ]),
    ]);

    $jobs = app(JsonApiScraper::class)->scrape(jsonApiSource('https://example.test/api', [
        'items_path' => 'jobs',
        'field_map' => ['title' => 'title', 'company' => 'company_name'],
    ]));

    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->title)->toBe('Real Role');
});

it('returns an empty list when items_path does not resolve to an array', function () {
    Http::fake([
        'example.test/*' => Http::response(['jobs' => 'not-an-array']),
    ]);

    $jobs = app(JsonApiScraper::class)->scrape(jsonApiSource('https://example.test/api', [
        'items_path' => 'jobs',
        'field_map' => ['title' => 'title', 'company' => 'company_name'],
    ]));

    expect($jobs)->toBe([]);
});

it('forwards configured request headers', function () {
    Http::fake([
        'example.test/*' => Http::response(['jobs' => []]),
    ]);

    app(JsonApiScraper::class)->scrape(jsonApiSource('https://example.test/api', [
        'items_path' => 'jobs',
        'headers' => ['User-Agent' => 'JobScraper/1.0'],
        'field_map' => ['title' => 'title', 'company' => 'company_name'],
    ]));

    Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'JobScraper/1.0'));
});

it('refuses to fetch a private/loopback address (SSRF guard)', function () {
    app(JsonApiScraper::class)->scrape(jsonApiSource('http://127.0.0.1/api', [
        'field_map' => ['title' => 'title', 'company' => 'company'],
    ]));
})->throws(RuntimeException::class);

it('refuses the cloud metadata endpoint (SSRF guard)', function () {
    app(JsonApiScraper::class)->scrape(jsonApiSource('http://169.254.169.254/latest/meta-data', [
        'field_map' => ['title' => 'title', 'company' => 'company'],
    ]));
})->throws(RuntimeException::class);

it('throws when the endpoint returns an error status', function () {
    Http::fake(['example.test/*' => Http::response('nope', 500)]);

    app(JsonApiScraper::class)->scrape(jsonApiSource('https://example.test/api', [
        'field_map' => ['title' => 'title', 'company' => 'company'],
    ]));
})->throws(RuntimeException::class);
