<?php

use App\Models\JobSource;
use App\Models\User;
use App\Services\Ats\AtsFeedScraper;
use Illuminate\Support\Facades\Http;

function atsSource(string $provider, string $token = 'acme'): JobSource
{
    return JobSource::factory()->make([
        'user_id' => User::factory()->create()->id,
        'type' => 'ats_feed',
        'config' => ['provider' => $provider, 'board_token' => $token],
    ]);
}

it('parses a greenhouse feed', function () {
    Http::fake([
        'boards-api.greenhouse.io/*' => Http::response([
            'jobs' => [[
                'title' => 'Backend Engineer',
                'location' => ['name' => 'Berlin (Hybrid)'],
                'content' => '&lt;p&gt;Ship APIs.&lt;/p&gt;&lt;ul&gt;&lt;li&gt;Go&lt;/li&gt;&lt;/ul&gt;',
                'absolute_url' => 'https://boards.greenhouse.io/acme/jobs/7',
                'updated_at' => '2026-06-15T10:00:00Z',
            ]],
        ]),
    ]);

    $jobs = app(AtsFeedScraper::class)->scrape(atsSource('greenhouse'));

    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->title)->toBe('Backend Engineer');
    expect($jobs[0]->company)->toBe('Acme');
    expect($jobs[0]->location)->toBe('Berlin (Hybrid)');
    expect($jobs[0]->remoteType)->toBe('hybrid');
    expect($jobs[0]->jdText)->toContain('Ship APIs');
    expect($jobs[0]->applyUrl)->toBe('https://boards.greenhouse.io/acme/jobs/7');
});

it('parses a lever feed (bare array)', function () {
    Http::fake([
        'api.lever.co/*' => Http::response([[
            'text' => 'Product Designer',
            'categories' => ['location' => 'Remote', 'commitment' => 'Full-time'],
            'descriptionPlain' => 'Design delightful things.',
            'hostedUrl' => 'https://jobs.lever.co/acme/1',
            'applyUrl' => 'https://jobs.lever.co/acme/1/apply',
            'createdAt' => 1_717_000_000_000,
        ]]),
    ]);

    $jobs = app(AtsFeedScraper::class)->scrape(atsSource('lever'));

    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->title)->toBe('Product Designer');
    expect($jobs[0]->remoteType)->toBe('remote');
    expect($jobs[0]->jdText)->toBe('Design delightful things.');
    expect($jobs[0]->applyUrl)->toBe('https://jobs.lever.co/acme/1/apply');
    expect($jobs[0]->postedAt)->not->toBeNull();
});

it('parses a workable feed and prefers the account name', function () {
    Http::fake([
        'workable.com/*' => Http::response([
            'name' => 'Acme Corp',
            'jobs' => [[
                'title' => 'Data Analyst',
                'location' => ['city' => 'Madrid', 'country' => 'Spain'],
                'workplace' => 'on-site',
                'description' => '&lt;p&gt;Crunch numbers.&lt;/p&gt;',
                'requirements' => '&lt;p&gt;SQL.&lt;/p&gt;',
                'shortlink' => 'https://apply.workable.com/acme/j/ABC',
                'published_on' => '2026-05-01',
            ]],
        ]),
    ]);

    $jobs = app(AtsFeedScraper::class)->scrape(atsSource('workable'));

    expect($jobs[0]->company)->toBe('Acme Corp');
    expect($jobs[0]->location)->toBe('Madrid, Spain');
    expect($jobs[0]->remoteType)->toBe('onsite');
    expect($jobs[0]->jdText)->toContain('Crunch numbers')->toContain('SQL');
});

it('parses an ashby feed using isRemote', function () {
    Http::fake([
        'api.ashbyhq.com/*' => Http::response([
            'jobs' => [[
                'title' => 'Platform Engineer',
                'location' => 'San Francisco',
                'isRemote' => true,
                'descriptionPlain' => 'Own the platform.',
                'applyUrl' => 'https://jobs.ashbyhq.com/acme/apply',
                'jobUrl' => 'https://jobs.ashbyhq.com/acme/1',
                'publishedAt' => '2026-04-20T00:00:00Z',
            ]],
        ]),
    ]);

    $jobs = app(AtsFeedScraper::class)->scrape(atsSource('ashby'));

    expect($jobs[0]->remoteType)->toBe('remote');
    expect($jobs[0]->jdText)->toBe('Own the platform.');
    expect($jobs[0]->applyUrl)->toBe('https://jobs.ashbyhq.com/acme/apply');
});

it('throws on an unsupported provider', function () {
    app(AtsFeedScraper::class)->scrape(atsSource('unknown'));
})->throws(InvalidArgumentException::class);

it('throws when the feed returns an error status', function () {
    Http::fake(['boards-api.greenhouse.io/*' => Http::response('nope', 500)]);

    app(AtsFeedScraper::class)->scrape(atsSource('greenhouse'));
})->throws(RuntimeException::class);
