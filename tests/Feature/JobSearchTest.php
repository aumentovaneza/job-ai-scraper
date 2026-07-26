<?php

use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
use App\Models\MatchScore;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

function statefulGetJson(string $uri)
{
    return test()->withHeader('Referer', config('app.url'))->getJson($uri);
}

it('requires authentication for the job feed', function () {
    getJson('/api/jobs')->assertUnauthorized();
});

it('returns a paginated list of jobs', function () {
    actingAs(User::factory()->create());
    JobPosting::factory()->count(3)->create();

    statefulGetJson('/api/jobs')
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'total', 'per_page']);
});

it('filters by remote type', function () {
    actingAs(User::factory()->create());
    JobPosting::factory()->create(['remote_type' => 'remote']);
    JobPosting::factory()->create(['remote_type' => 'onsite']);

    $response = statefulGetJson('/api/jobs?remote_type=remote')->assertOk();

    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.remote_type'))->toBe('remote');
});

it('filters by minimum salary against the upper band', function () {
    actingAs(User::factory()->create());
    JobPosting::factory()->create(['salary_min' => 90_000, 'salary_max' => 120_000]);
    JobPosting::factory()->create(['salary_min' => 40_000, 'salary_max' => 60_000]);

    $response = statefulGetJson('/api/jobs?salary_min=100000')->assertOk();

    expect($response->json('total'))->toBe(1);
});

it('matches keyword search against title and jd text (portable fallback)', function () {
    actingAs(User::factory()->create());
    JobPosting::factory()->create(['title' => 'Senior Rust Engineer', 'jd_text' => 'systems work']);
    JobPosting::factory()->create(['title' => 'Marketing Manager', 'jd_text' => 'brand strategy']);

    $response = statefulGetJson('/api/jobs?q=Rust')->assertOk();

    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.title'))->toBe('Senior Rust Engineer');
});

it('filters by a source the user owns', function () {
    $user = User::factory()->create();
    actingAs($user);

    $source = JobSource::factory()->create(['user_id' => $user->id]);
    $matched = JobPosting::factory()->create();
    JobPosting::factory()->create(); // unrelated posting
    JobSourceHit::factory()->create([
        'job_posting_id' => $matched->id,
        'job_source_id' => $source->id,
    ]);

    $response = statefulGetJson("/api/jobs?source_id={$source->id}")->assertOk();

    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($matched->id);
});

it('ignores a source_id that belongs to another user', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $otherSource = JobSource::factory()->create(['user_id' => $other->id]);

    JobPosting::factory()->count(2)->create();

    actingAs($me);
    // The foreign source_id is dropped, so the full catalog is returned rather
    // than filtering by (or leaking) another user's source.
    $response = statefulGetJson("/api/jobs?source_id={$otherSource->id}")->assertOk();

    expect($response->json('total'))->toBe(2);
});

it('attaches only the current user\'s match score to each posting (T-32)', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $posting = JobPosting::factory()->create();

    // My score should surface; the other user's score for the same posting must not.
    MatchScore::withoutGlobalScopes()->create([
        'user_id' => $me->id, 'job_posting_id' => $posting->id,
        'score' => 82, 'reasoning' => 'Good fit', 'strengths' => ['x'], 'gaps' => [],
        'prompt_version' => 'match_score.v1', 'input_hash' => 'abc', 'computed_at' => now(),
    ]);
    MatchScore::withoutGlobalScopes()->create([
        'user_id' => $other->id, 'job_posting_id' => $posting->id,
        'score' => 10, 'reasoning' => 'Weak', 'strengths' => [], 'gaps' => ['y'],
        'prompt_version' => 'match_score.v1', 'input_hash' => 'def', 'computed_at' => now(),
    ]);

    actingAs($me);
    $response = statefulGetJson('/api/jobs')->assertOk();

    expect($response->json('data.0.match_score.score'))->toBe(82);
    // The internal cache fingerprint is never exposed.
    expect($response->json('data.0.match_score'))->not->toHaveKey('input_hash');
});

it('returns a null match score for a posting the user has not been scored on', function () {
    actingAs(User::factory()->create());
    JobPosting::factory()->create();

    $response = statefulGetJson('/api/jobs')->assertOk();

    expect($response->json('data.0.match_score'))->toBeNull();
});

/**
 * Create a MatchScore for the given user/posting without tripping the
 * BelongsToUser global scope (which would block cross-user seeding).
 */
function seedScore(User $user, JobPosting $posting, ?int $score): void
{
    MatchScore::withoutGlobalScopes()->create([
        'user_id' => $user->id, 'job_posting_id' => $posting->id,
        'score' => $score, 'reasoning' => 'r', 'strengths' => [], 'gaps' => [],
        'prompt_version' => 'match_score.v1', 'input_hash' => uniqid(), 'computed_at' => now(),
    ]);
}

it('filters to jobs the user has scored', function () {
    $me = User::factory()->create();
    $scored = JobPosting::factory()->create();
    JobPosting::factory()->create(); // never scored

    seedScore($me, $scored, 70);

    actingAs($me);
    $response = statefulGetJson('/api/jobs?score_status=scored')->assertOk();

    expect($response->json('total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($scored->id);
});

it('filters to jobs the user has not yet scored', function () {
    $me = User::factory()->create();
    $scored = JobPosting::factory()->create();
    $unscored = JobPosting::factory()->create();
    $nullScored = JobPosting::factory()->create();

    seedScore($me, $scored, 70);
    seedScore($me, $nullScored, null); // a row exists but score is null → still "unscored"

    actingAs($me);
    $response = statefulGetJson('/api/jobs?score_status=unscored')->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($response->json('total'))->toBe(2);
    expect($ids)->toContain($unscored->id)->toContain($nullScored->id)->not->toContain($scored->id);
});

it('treats another user\'s score as unscored for me', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $posting = JobPosting::factory()->create();

    seedScore($other, $posting, 90);

    actingAs($me);
    // Only the other user scored it, so for me it is unscored.
    expect(statefulGetJson('/api/jobs?score_status=scored')->assertOk()->json('total'))->toBe(0);
    expect(statefulGetJson('/api/jobs?score_status=unscored')->assertOk()->json('total'))->toBe(1);
});

it('filters by a min/max score range', function () {
    $me = User::factory()->create();
    $low = JobPosting::factory()->create();
    $mid = JobPosting::factory()->create();
    $high = JobPosting::factory()->create();

    seedScore($me, $low, 20);
    seedScore($me, $mid, 60);
    seedScore($me, $high, 95);

    actingAs($me);

    expect(statefulGetJson('/api/jobs?score_min=50')->assertOk()->json('total'))->toBe(2);
    expect(statefulGetJson('/api/jobs?score_max=50')->assertOk()->json('total'))->toBe(1);

    $range = statefulGetJson('/api/jobs?score_min=50&score_max=80')->assertOk();
    expect($range->json('total'))->toBe(1);
    expect($range->json('data.0.id'))->toBe($mid->id);
});

it('excludes unscored jobs when a score range is given', function () {
    $me = User::factory()->create();
    $scored = JobPosting::factory()->create();
    JobPosting::factory()->create(); // unscored

    seedScore($me, $scored, 40);

    actingAs($me);
    // A range implies "scored" — the unscored posting must not leak through.
    expect(statefulGetJson('/api/jobs?score_min=0')->assertOk()->json('total'))->toBe(1);
});
