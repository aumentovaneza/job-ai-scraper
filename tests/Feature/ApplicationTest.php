<?php

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationStage;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\DefaultStages;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * Stateful (first-party SPA) request, mirroring how the React frontend calls
 * the API. Named distinctly to avoid clashing with other feature-test helpers.
 */
function appRequest()
{
    return test()->withHeader('Referer', config('app.url'));
}

/**
 * A user with the default pipeline already seeded.
 */
function userWithStages(): User
{
    $user = User::factory()->create();
    DefaultStages::seedFor($user);

    return $user;
}

// --- Auth -------------------------------------------------------------------

it('requires authentication to list applications', function () {
    getJson('/api/applications')->assertUnauthorized();
});

// --- Create -----------------------------------------------------------------

it('starts tracking a job in the first stage and logs a created event', function () {
    $me = userWithStages();
    $job = JobPosting::factory()->create();
    actingAs($me);

    $response = appRequest()->postJson('/api/applications', ['job_posting_id' => $job->id])
        ->assertCreated();

    $saved = ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $me->id)->orderBy('position')->first();

    expect($response->json('data.current_stage_id'))->toBe($saved->id);
    expect($response->json('data.applied_at'))->toBeNull();

    $this->assertDatabaseHas('application_events', [
        'user_id' => $me->id,
        'type' => ApplicationEvent::TYPE_CREATED,
        'to_stage_id' => $saved->id,
    ]);
});

it('stamps applied_at when created directly into a non-initial stage', function () {
    $me = userWithStages();
    $job = JobPosting::factory()->create();
    $applied = ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $me->id)->where('name', 'Applied')->first();
    actingAs($me);

    $response = appRequest()->postJson('/api/applications', [
        'job_posting_id' => $job->id,
        'stage_id' => $applied->id,
    ])->assertCreated();

    expect($response->json('data.current_stage_id'))->toBe($applied->id);
    expect($response->json('data.applied_at'))->not->toBeNull();
});

it('rejects tracking the same job twice', function () {
    $me = userWithStages();
    $job = JobPosting::factory()->create();
    actingAs($me);

    appRequest()->postJson('/api/applications', ['job_posting_id' => $job->id])->assertCreated();
    appRequest()->postJson('/api/applications', ['job_posting_id' => $job->id])
        ->assertStatus(422)->assertJsonValidationErrors('job_posting_id');
});

it('lets two users independently track the same job', function () {
    $a = userWithStages();
    $b = userWithStages();
    $job = JobPosting::factory()->create();

    actingAs($a);
    appRequest()->postJson('/api/applications', ['job_posting_id' => $job->id])->assertCreated();
    actingAs($b);
    appRequest()->postJson('/api/applications', ['job_posting_id' => $job->id])->assertCreated();

    expect(Application::withoutGlobalScopes()->where('job_posting_id', $job->id)->count())->toBe(2);
});

// --- Index ------------------------------------------------------------------

it('lists only the current user\'s applications', function () {
    $me = userWithStages();
    $other = userWithStages();
    Application::factory()->count(2)->create(['user_id' => $me->id]);
    Application::factory()->create(['user_id' => $other->id]);

    actingAs($me);
    $response = appRequest()->getJson('/api/applications')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

// --- Move -------------------------------------------------------------------

it('moves an application and logs a stage_changed event', function () {
    $me = userWithStages();
    $job = JobPosting::factory()->create();
    actingAs($me);
    $application = app(ApplicationService::class)->createFromJob($me, $job);

    $onsite = ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $me->id)->where('name', 'Onsite')->first();

    $response = appRequest()->patchJson("/api/applications/{$application->id}/move", [
        'target_stage_id' => $onsite->id,
    ])->assertOk();

    expect($response->json('data.current_stage_id'))->toBe($onsite->id);
    expect($response->json('data.applied_at'))->not->toBeNull();

    $this->assertDatabaseHas('application_events', [
        'application_id' => $application->id,
        'type' => ApplicationEvent::TYPE_STAGE_CHANGED,
        'from_stage_id' => $application->current_stage_id,
        'to_stage_id' => $onsite->id,
    ]);
});

it('is a no-op when moving to the stage it is already in', function () {
    $me = userWithStages();
    $job = JobPosting::factory()->create();
    actingAs($me);
    $application = app(ApplicationService::class)->createFromJob($me, $job);

    appRequest()->patchJson("/api/applications/{$application->id}/move", [
        'target_stage_id' => $application->current_stage_id,
    ])->assertOk();

    // Only the original `created` event exists — no redundant stage_changed row.
    expect(ApplicationEvent::withoutGlobalScopes()->where('application_id', $application->id)->count())
        ->toBe(1);
});

// --- Notes & contacts -------------------------------------------------------

it('logs a note on the timeline', function () {
    $me = userWithStages();
    $application = Application::factory()->create(['user_id' => $me->id]);
    actingAs($me);

    appRequest()->postJson("/api/applications/{$application->id}/notes", [
        'body' => 'Recruiter said budget is approved.',
    ])->assertCreated();

    $this->assertDatabaseHas('application_events', [
        'application_id' => $application->id,
        'type' => ApplicationEvent::TYPE_NOTE,
    ]);
    $event = ApplicationEvent::withoutGlobalScopes()
        ->where('application_id', $application->id)->where('type', 'note')->first();
    expect($event->metadata['body'])->toBe('Recruiter said budget is approved.');
});

it('attaches a contact and logs it', function () {
    $me = userWithStages();
    $application = Application::factory()->create(['user_id' => $me->id]);
    actingAs($me);

    appRequest()->postJson("/api/applications/{$application->id}/contacts", [
        'name' => 'Dana Recruiter',
        'role' => 'Recruiter',
        'email' => 'dana@acme.test',
    ])->assertCreated()->assertJsonPath('data.name', 'Dana Recruiter');

    $this->assertDatabaseHas('contacts', [
        'application_id' => $application->id,
        'user_id' => $me->id,
        'name' => 'Dana Recruiter',
    ]);
    $this->assertDatabaseHas('application_events', [
        'application_id' => $application->id,
        'type' => ApplicationEvent::TYPE_CONTACT_ADDED,
    ]);
});

// --- Show / timeline --------------------------------------------------------

it('returns the timeline, contacts and current stage on show', function () {
    $me = userWithStages();
    $job = JobPosting::factory()->create();
    actingAs($me);
    $service = app(ApplicationService::class);
    $application = $service->createFromJob($me, $job);
    $service->addNote($application, 'first note');

    $response = appRequest()->getJson("/api/applications/{$application->id}")->assertOk();

    expect($response->json('data.events'))->toHaveCount(2); // created + note
    expect($response->json('data.job_posting.id'))->toBe($job->id);
    expect($response->json('data.current_stage.name'))->toBe('Saved');
});

// --- Event sourcing ---------------------------------------------------------

it('can rebuild current_stage_id from the event log', function () {
    $me = userWithStages();
    $job = JobPosting::factory()->create();
    $service = app(ApplicationService::class);
    $application = $service->createFromJob($me, $job);
    $onsite = ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $me->id)->where('name', 'Onsite')->first();
    $service->moveToStage($application, $onsite);

    // Corrupt the denormalized projection, then rebuild it from events.
    $application->forceFill(['current_stage_id' => null])->save();
    $rebuilt = $service->rebuildCurrentStage($application);

    expect($rebuilt)->toBe($onsite->id);
    expect($application->fresh()->current_stage_id)->toBe($onsite->id);
});

// --- Multi-tenant isolation (mandatory per PLAN.md §7) ----------------------

it('cannot view, move, note or add contacts on another user\'s application', function () {
    $me = userWithStages();
    $other = userWithStages();
    $foreign = Application::factory()->create(['user_id' => $other->id]);
    $myStage = ApplicationStage::withoutGlobalScopes()->where('user_id', $me->id)->first();

    actingAs($me);

    appRequest()->getJson("/api/applications/{$foreign->id}")->assertNotFound();
    appRequest()->patchJson("/api/applications/{$foreign->id}/move", ['target_stage_id' => $myStage->id])
        ->assertNotFound();
    appRequest()->postJson("/api/applications/{$foreign->id}/notes", ['body' => 'x'])->assertNotFound();
    appRequest()->postJson("/api/applications/{$foreign->id}/contacts", ['name' => 'x'])->assertNotFound();
});

it('cannot move an application into another user\'s stage', function () {
    $me = userWithStages();
    $other = userWithStages();
    $application = Application::factory()->create(['user_id' => $me->id]);
    $foreignStage = ApplicationStage::withoutGlobalScopes()->where('user_id', $other->id)->first();

    actingAs($me);
    appRequest()->patchJson("/api/applications/{$application->id}/move", [
        'target_stage_id' => $foreignStage->id,
    ])->assertStatus(422)->assertJsonValidationErrors('target_stage_id');
});
