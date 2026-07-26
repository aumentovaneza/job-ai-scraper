<?php

use App\Models\JobSource;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;

function statefulJson()
{
    return test()->withHeader('Referer', config('app.url'));
}

// --- Auth -------------------------------------------------------------------

it('requires authentication to list job sources', function () {
    getJson('/api/job-sources')->assertUnauthorized();
});

// --- CRUD -------------------------------------------------------------------

it('lists only the current user\'s sources', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    JobSource::factory()->count(2)->create(['user_id' => $me->id]);
    JobSource::factory()->create(['user_id' => $other->id]);

    actingAs($me);
    $response = statefulJson()->getJson('/api/job-sources')->assertOk();

    expect($response->json('total'))->toBe(2);
});

it('creates an ATS feed source and stamps the owner', function () {
    $me = User::factory()->create();
    actingAs($me);

    $payload = [
        'type' => 'ats_feed',
        'config' => ['provider' => 'greenhouse', 'board_token' => 'acme'],
        'cron_schedule' => '0 * * * *',
        'active' => true,
    ];

    $response = statefulJson()->postJson('/api/job-sources', $payload)->assertCreated();

    expect($response->json('data.type'))->toBe('ats_feed');
    expect($response->json('data.config.provider'))->toBe('greenhouse');

    $this->assertDatabaseHas('job_sources', [
        'user_id' => $me->id,
        'type' => 'ats_feed',
    ]);
});

it('rejects an ATS feed source without a provider', function () {
    actingAs(User::factory()->create());

    statefulJson()->postJson('/api/job-sources', [
        'type' => 'ats_feed',
        'config' => ['board_token' => 'acme'],
    ])->assertStatus(422)->assertJsonValidationErrors('config.provider');
});

it('requires a url for a career page source', function () {
    actingAs(User::factory()->create());

    statefulJson()->postJson('/api/job-sources', [
        'type' => 'career_page',
    ])->assertStatus(422)->assertJsonValidationErrors('url');
});

it('rejects an invalid cron schedule', function () {
    actingAs(User::factory()->create());

    statefulJson()->postJson('/api/job-sources', [
        'type' => 'ats_feed',
        'config' => ['provider' => 'lever', 'board_token' => 'acme'],
        'cron_schedule' => 'not-a-cron',
    ])->assertStatus(422)->assertJsonValidationErrors('cron_schedule');
});

it('updates a source it owns', function () {
    $me = User::factory()->create();
    actingAs($me);
    $source = JobSource::factory()->create(['user_id' => $me->id, 'active' => true]);

    statefulJson()->patchJson("/api/job-sources/{$source->id}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false);

    expect($source->fresh()->active)->toBeFalse();
});

it('deletes a source it owns', function () {
    $me = User::factory()->create();
    actingAs($me);
    $source = JobSource::factory()->create(['user_id' => $me->id]);

    statefulJson()->deleteJson("/api/job-sources/{$source->id}")->assertNoContent();

    $this->assertDatabaseMissing('job_sources', ['id' => $source->id]);
});

// --- Multi-tenant isolation (mandatory per PLAN.md §7) ----------------------

it('cannot view another user\'s source via update/delete (404 from scope)', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $foreign = JobSource::factory()->create(['user_id' => $other->id]);

    actingAs($me);

    // The global scope hides the row entirely → route binding 404s.
    statefulJson()->patchJson("/api/job-sources/{$foreign->id}", ['active' => false])
        ->assertNotFound();
    deleteJson("/api/job-sources/{$foreign->id}")->assertNotFound();

    expect($foreign->fresh()->active)->toBe($foreign->active);
});

it('cannot test-scrape another user\'s source', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $foreign = JobSource::factory()->create(['user_id' => $other->id]);

    actingAs($me);
    statefulJson()->postJson("/api/job-sources/{$foreign->id}/test-scrape")
        ->assertNotFound();
});

// --- Test scrape ------------------------------------------------------------

it('test-scrapes a greenhouse source without persisting', function () {
    $me = User::factory()->create();
    actingAs($me);

    $source = JobSource::factory()->create([
        'user_id' => $me->id,
        'type' => 'ats_feed',
        'config' => ['provider' => 'greenhouse', 'board_token' => 'acme'],
    ]);

    Http::fake([
        'boards-api.greenhouse.io/*' => Http::response([
            'jobs' => [
                [
                    'title' => 'Staff Engineer',
                    'location' => ['name' => 'Remote - US'],
                    'content' => '&lt;p&gt;Build things.&lt;/p&gt;',
                    'absolute_url' => 'https://boards.greenhouse.io/acme/jobs/1',
                    'updated_at' => '2026-07-01T00:00:00Z',
                ],
            ],
        ]),
    ]);

    $response = statefulJson()->postJson("/api/job-sources/{$source->id}/test-scrape")->assertOk();

    expect($response->json('count'))->toBe(1);
    expect($response->json('jobs.0.title'))->toBe('Staff Engineer');
    expect($response->json('jobs.0.remote_type'))->toBe('remote');

    // Nothing persisted — test scrape is a pure preview.
    $this->assertDatabaseCount('job_postings', 0);
});

it('test-scrapes an RSS 2.0 feed without persisting', function () {
    $me = User::factory()->create();
    actingAs($me);

    $source = JobSource::factory()->create([
        'user_id' => $me->id,
        'type' => 'rss',
        'url' => 'https://jobs.test/feed.xml',
        'config' => ['company' => 'Acme'],
    ]);

    Http::fake([
        'jobs.test/*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <title>Acme Jobs</title>
            <item>
              <title>Senior Remote Engineer</title>
              <link>https://jobs.test/roles/1</link>
              <description>&lt;p&gt;Build remote things.&lt;/p&gt;</description>
              <pubDate>Wed, 01 Jul 2026 00:00:00 +0000</pubDate>
            </item>
          </channel>
        </rss>
        XML, 200, ['Content-Type' => 'application/rss+xml']),
    ]);

    $response = statefulJson()->postJson("/api/job-sources/{$source->id}/test-scrape")->assertOk();

    expect($response->json('count'))->toBe(1);
    expect($response->json('jobs.0.title'))->toBe('Senior Remote Engineer');
    expect($response->json('jobs.0.company'))->toBe('Acme');
    expect($response->json('jobs.0.apply_url'))->toBe('https://jobs.test/roles/1');
    expect($response->json('jobs.0.remote_type'))->toBe('remote');
    expect($response->json('jobs.0.jd_text'))->toContain('Build remote things');

    $this->assertDatabaseCount('job_postings', 0);
});

it('test-scrapes an Atom feed without persisting', function () {
    $me = User::factory()->create();
    actingAs($me);

    $source = JobSource::factory()->create([
        'user_id' => $me->id,
        'type' => 'rss',
        'url' => 'https://atom.test/feed',
        'config' => [],
    ]);

    Http::fake([
        'atom.test/*' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <title>Atom Jobs</title>
          <entry>
            <title>Platform Engineer</title>
            <link rel="alternate" href="https://atom.test/roles/9"/>
            <summary>Own the platform.</summary>
            <published>2026-07-02T00:00:00Z</published>
          </entry>
        </feed>
        XML, 200, ['Content-Type' => 'application/atom+xml']),
    ]);

    $response = statefulJson()->postJson("/api/job-sources/{$source->id}/test-scrape")->assertOk();

    expect($response->json('count'))->toBe(1);
    expect($response->json('jobs.0.title'))->toBe('Platform Engineer');
    // No config company / feed title falls back to feed <title>.
    expect($response->json('jobs.0.company'))->toBe('Atom Jobs');
    expect($response->json('jobs.0.apply_url'))->toBe('https://atom.test/roles/9');

    $this->assertDatabaseCount('job_postings', 0);
});

it('returns a 422 when an RSS feed is not valid XML', function () {
    $me = User::factory()->create();
    actingAs($me);

    $source = JobSource::factory()->create([
        'user_id' => $me->id,
        'type' => 'rss',
        'url' => 'https://broken.test/feed',
    ]);

    Http::fake(['broken.test/*' => Http::response('not xml at all', 200)]);

    statefulJson()->postJson("/api/job-sources/{$source->id}/test-scrape")
        ->assertStatus(422)
        ->assertJsonPath('message', 'RSS feed https://broken.test/feed did not return valid XML.');
});
