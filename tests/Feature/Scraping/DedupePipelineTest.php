<?php

use App\Jobs\DedupeJobJob;
use App\Jobs\EmbedJobPostingJob;
use App\Jobs\EnrichJobJob;
use App\Jobs\ScrapeAtsFeedJob;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
use App\Models\User;
use App\Services\Ats\AtsFeedScraper;
use App\Services\JobIngestionService;
use App\Support\NormalizedJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function greenhouseSource(string $token = 'acme'): JobSource
{
    return JobSource::factory()->create([
        'user_id' => User::factory()->create()->id,
        'type' => 'ats_feed',
        'config' => ['provider' => 'greenhouse', 'board_token' => $token],
    ]);
}

function samplePosting(): NormalizedJob
{
    return new NormalizedJob(
        title: 'Senior Go Engineer',
        company: 'Acme',
        location: 'Remote',
        remoteType: 'remote',
        jdText: 'Write Go all day.',
        applyUrl: 'https://acme.example/jobs/1',
        sourceUrl: 'https://acme.example/jobs/1',
    );
}

function runDedupe(NormalizedJob $job, JobSource $source): void
{
    (new DedupeJobJob($job->toArray(), $source->id))->handle(app(JobIngestionService::class));
}

it('creates a canonical posting and a source hit for a new job', function () {
    Queue::fake();
    $source = greenhouseSource();

    runDedupe(samplePosting(), $source);

    expect(JobPosting::count())->toBe(1);
    expect(JobSourceHit::where('job_source_id', $source->id)->count())->toBe(1);
    Queue::assertPushed(EmbedJobPostingJob::class, 1);
    // A new posting also kicks off AI enrichment, funded by the source's user.
    Queue::assertPushed(EnrichJobJob::class, 1);
});

it('persists tags onto the canonical posting', function () {
    Queue::fake();
    $source = greenhouseSource();

    runDedupe(new NormalizedJob(
        title: 'Senior Go Engineer',
        company: 'Acme',
        location: 'Remote',
        tags: ['golang', 'backend'],
    ), $source);

    expect(JobPosting::first()->tags)->toBe(['golang', 'backend']);
});

it('is idempotent: the same job twice yields one posting and one hit', function () {
    Queue::fake();
    $source = greenhouseSource();

    runDedupe(samplePosting(), $source);
    runDedupe(samplePosting(), $source);

    expect(JobPosting::count())->toBe(1);
    expect(JobSourceHit::count())->toBe(1);
    // Only the first sighting was new, so embedding fires exactly once.
    Queue::assertPushed(EmbedJobPostingJob::class, 1);
});

it('collapses the same job from two sources into one posting with two hits', function () {
    Queue::fake();
    $a = greenhouseSource('acme');
    $b = greenhouseSource('acme-eu');

    runDedupe(samplePosting(), $a);
    runDedupe(samplePosting(), $b);

    expect(JobPosting::count())->toBe(1);
    expect(JobSourceHit::count())->toBe(2);
    Queue::assertPushed(EmbedJobPostingJob::class, 1);
});

it('treats a different title as a distinct posting', function () {
    Queue::fake();
    $source = greenhouseSource();

    runDedupe(samplePosting(), $source);
    runDedupe(new NormalizedJob(title: 'Junior Go Engineer', company: 'Acme', location: 'Remote'), $source);

    expect(JobPosting::count())->toBe(2);
});

it('normalizes cosmetic title/whitespace differences to the same hash', function () {
    Queue::fake();
    $source = greenhouseSource();

    runDedupe(samplePosting(), $source);
    runDedupe(new NormalizedJob(
        title: '  Senior   Go   ENGINEER ',
        company: 'ACME',
        location: 'remote',
    ), $source);

    expect(JobPosting::count())->toBe(1);
});

it('fans out one DedupeJobJob per posting when scraping a feed', function () {
    Http::fake([
        'boards-api.greenhouse.io/*' => Http::response([
            'jobs' => [
                ['title' => 'Role A', 'location' => ['name' => 'Remote'], 'absolute_url' => 'https://x/1', 'updated_at' => '2026-06-01'],
                ['title' => 'Role B', 'location' => ['name' => 'Remote'], 'absolute_url' => 'https://x/2', 'updated_at' => '2026-06-01'],
            ],
        ]),
    ]);
    Queue::fake();
    $source = greenhouseSource();

    (new ScrapeAtsFeedJob($source->id))->handle(app(AtsFeedScraper::class));

    Queue::assertPushed(DedupeJobJob::class, 2);
    expect($source->fresh()->last_scraped_at)->not->toBeNull();
});

it('runs end to end (sync) from feed to persisted postings', function () {
    Http::fake([
        'boards-api.greenhouse.io/*' => Http::response([
            'jobs' => [
                ['title' => 'Role A', 'location' => ['name' => 'Remote'], 'content' => 'a', 'absolute_url' => 'https://x/1', 'updated_at' => '2026-06-01'],
                ['title' => 'Role B', 'location' => ['name' => 'Remote'], 'content' => 'b', 'absolute_url' => 'https://x/2', 'updated_at' => '2026-06-01'],
            ],
        ]),
    ]);
    $source = greenhouseSource();

    ScrapeAtsFeedJob::dispatchSync($source->id);

    expect(JobPosting::count())->toBe(2);
    expect(JobSourceHit::where('job_source_id', $source->id)->count())->toBe(2);
});
