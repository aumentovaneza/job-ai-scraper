<?php

use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
use App\Models\User;
use App\Services\JobSearchService;

use function Pest\Laravel\actingAs;

function acceptabilityGetJson(string $uri)
{
    return test()->withHeader('Referer', config('app.url'))->getJson($uri);
}

/** Link a posting to a source (a JobSourceHit is the posting↔source bridge). */
function linkSourceHit(JobPosting $posting, JobSource $source): void
{
    JobSourceHit::factory()->create([
        'job_posting_id' => $posting->id,
        'job_source_id' => $source->id,
    ]);
}

it('ranks postings from acceptable sources above penalized ones', function () {
    $me = User::factory()->create();
    actingAs($me);

    $good = JobSource::factory()->create([
        'user_id' => $me->id,
        'hires_internationally' => true,
        'timezone_overlap' => 'any',
    ]);
    $bad = JobSource::factory()->create([
        'user_id' => $me->id,
        'hires_internationally' => false,
        'timezone_overlap' => 'strict',
    ]);

    // The penalized posting is NEWER, so recency alone would float it to the
    // top — acceptability must override and push it below.
    $acceptable = JobPosting::factory()->create(['posted_at' => now()->subDays(5)]);
    $penalized = JobPosting::factory()->create(['posted_at' => now()]);

    linkSourceHit($acceptable, $good);
    linkSourceHit($penalized, $bad);

    $ids = acceptabilityGetJson('/api/jobs')->assertOk()->json('data.*.id');

    expect(array_search($acceptable->id, $ids))->toBeLessThan(array_search($penalized->id, $ids));
});

it('ranks a posting by its best (lowest-penalty) linking source', function () {
    $me = User::factory()->create();
    actingAs($me);

    $good = JobSource::factory()->create([
        'user_id' => $me->id,
        'hires_internationally' => true,
        'timezone_overlap' => 'any',
    ]);
    $bad = JobSource::factory()->create([
        'user_id' => $me->id,
        'hires_internationally' => false,
        'timezone_overlap' => 'strict',
    ]);

    $penalized = JobPosting::factory()->create(['posted_at' => now()]);
    // Surfaced by BOTH a bad and a good source → penalty is the good one (0).
    $both = JobPosting::factory()->create(['posted_at' => now()->subDay()]);

    linkSourceHit($penalized, $bad);
    linkSourceHit($both, $bad);
    linkSourceHit($both, $good);

    $ids = acceptabilityGetJson('/api/jobs')->assertOk()->json('data.*.id');

    expect(array_search($both->id, $ids))->toBeLessThan(array_search($penalized->id, $ids));
});

it('does not reorder when no user is supplied to the search service', function () {
    $me = User::factory()->create();
    $bad = JobSource::factory()->create([
        'user_id' => $me->id,
        'hires_internationally' => false,
        'timezone_overlap' => 'strict',
    ]);

    $older = JobPosting::factory()->create(['posted_at' => now()->subDays(5)]);
    $newer = JobPosting::factory()->create(['posted_at' => now()]);
    linkSourceHit($newer, $bad);

    // Without a user, ordering is pure recency — the penalized (newer) posting
    // still comes first.
    $ids = app(JobSearchService::class)->search([])->pluck('id')->all();

    expect($ids[0])->toBe($newer->id);
});
