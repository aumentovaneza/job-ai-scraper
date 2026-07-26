<?php

use App\Jobs\GenerateFollowUpJob;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationStage;
use App\Models\FollowUp;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use App\Services\ApplicationService;
use App\Support\DefaultStages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

function followUpRequest()
{
    return test()->withHeader('Referer', config('app.url'));
}

function fakeFollowUpDraft(string $text = 'Hi there, just checking in on the Staff Engineer role…'): void
{
    Http::fake(['api.anthropic.com/*' => Http::response([
        'model' => 'claude-opus-4-8',
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 400, 'output_tokens' => 120],
    ], 200)]);
}

/**
 * A user with an Anthropic key + seeded pipeline, plus a stale, applied
 * application sitting in the given stage.
 */
function staleApplication(string $stageName = 'Applied', int $ageDays = 10): array
{
    $user = User::factory()->create();
    app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');
    DefaultStages::seedFor($user);
    $user->profile()->create(['headline' => 'Staff Engineer', 'resume_text' => 'x']);

    $stage = ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $user->id)->where('name', $stageName)->first();

    $job = JobPosting::factory()->create(['title' => 'Staff Engineer', 'company' => 'Acme']);
    $application = app(ApplicationService::class)->createFromJob($user, $job, $stage);

    // Backdate the timeline so the application reads as stale.
    ApplicationEvent::withoutGlobalScopes()
        ->where('application_id', $application->id)
        ->update(['occurred_at' => now()->subDays($ageDays)]);
    $application->forceFill(['applied_at' => now()->subDays($ageDays)])->save();

    return [$user, $application->fresh()];
}

// --- GenerateFollowUpJob ----------------------------------------------------

it('drafts a pending follow-up and logs a timeline event', function () {
    fakeFollowUpDraft();
    [$user, $application] = staleApplication();

    (new GenerateFollowUpJob($application->id))->handle(
        app(AiClientFactory::class),
        app(ApplicationService::class),
    );

    $followUp = FollowUp::withoutGlobalScopes()->where('application_id', $application->id)->first();
    expect($followUp)->not->toBeNull();
    expect($followUp->status)->toBe('pending');
    expect($followUp->user_id)->toBe($user->id);
    expect($followUp->draft_content)->toContain('Staff Engineer');

    $this->assertDatabaseHas('application_events', [
        'application_id' => $application->id,
        'type' => ApplicationEvent::TYPE_FOLLOW_UP_DRAFTED,
        'actor' => 'claude',
    ]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
});

it('does not draft a follow-up for a terminal application', function () {
    fakeFollowUpDraft();
    [, $application] = staleApplication('Rejected');

    (new GenerateFollowUpJob($application->id))->handle(
        app(AiClientFactory::class),
        app(ApplicationService::class),
    );

    expect(FollowUp::withoutGlobalScopes()->where('application_id', $application->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('does not draft a second follow-up when one is already active', function () {
    fakeFollowUpDraft();
    [$user, $application] = staleApplication();
    FollowUp::factory()->create([
        'user_id' => $user->id,
        'application_id' => $application->id,
        'status' => 'pending',
    ]);

    (new GenerateFollowUpJob($application->id))->handle(
        app(AiClientFactory::class),
        app(ApplicationService::class),
    );

    expect(FollowUp::withoutGlobalScopes()->where('application_id', $application->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('does not draft a follow-up when the application is not stale yet', function () {
    fakeFollowUpDraft();
    [, $application] = staleApplication('Applied', ageDays: 1);

    (new GenerateFollowUpJob($application->id, staleDays: 7))->handle(
        app(AiClientFactory::class),
        app(ApplicationService::class),
    );

    expect(FollowUp::withoutGlobalScopes()->where('application_id', $application->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

// --- DraftFollowUpsCommand --------------------------------------------------

it('dispatches a draft job only for stale applied applications', function () {
    Queue::fake();

    [, $stale] = staleApplication('Applied', ageDays: 10);

    // A fresh application (applied today) should be skipped.
    [$freshUser] = staleApplication('Applied', ageDays: 0);
    $freshApp = Application::withoutGlobalScopes()->where('user_id', $freshUser->id)->first();

    $this->artisan('follow-ups:draft', ['--days' => 7])->assertSuccessful();

    Queue::assertPushed(GenerateFollowUpJob::class, 1);
    Queue::assertPushed(GenerateFollowUpJob::class, fn ($job) => $job->applicationId === $stale->id);
    Queue::assertNotPushed(GenerateFollowUpJob::class, fn ($job) => $job->applicationId === $freshApp->id);
});

// --- Inbox API --------------------------------------------------------------

it('lists only the current user\'s active follow-ups', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    FollowUp::factory()->count(2)->create(['user_id' => $me->id, 'status' => 'pending']);
    FollowUp::factory()->create(['user_id' => $me->id, 'status' => 'dismissed']);
    FollowUp::factory()->create(['user_id' => $other->id, 'status' => 'pending']);

    actingAs($me);
    $response = followUpRequest()->getJson('/api/follow-ups')->assertOk();

    expect($response->json('data'))->toHaveCount(2); // active only, mine only
});

it('marks a follow-up as sent', function () {
    $me = User::factory()->create();
    $followUp = FollowUp::factory()->create(['user_id' => $me->id, 'status' => 'pending']);

    actingAs($me);
    followUpRequest()->patchJson("/api/follow-ups/{$followUp->id}", ['status' => 'sent'])
        ->assertOk()->assertJsonPath('data.status', 'sent');

    expect($followUp->fresh()->status)->toBe('sent');
});

it('cannot update another user\'s follow-up (404 from scope)', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $foreign = FollowUp::factory()->create(['user_id' => $other->id, 'status' => 'pending']);

    actingAs($me);
    followUpRequest()->patchJson("/api/follow-ups/{$foreign->id}", ['status' => 'dismissed'])
        ->assertNotFound();

    expect($foreign->fresh()->status)->toBe('pending');
});
