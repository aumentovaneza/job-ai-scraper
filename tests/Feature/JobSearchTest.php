<?php

use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
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
