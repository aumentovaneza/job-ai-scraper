<?php

use App\Jobs\GenerateLetterJob;
use App\Models\AiCall;
use App\Models\Application;
use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use App\Services\ApplicationService;
use App\Support\DefaultStages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

function letterApi()
{
    return test()->withHeader('Referer', config('app.url'));
}

/**
 * A user with a seeded pipeline + an application to draft a letter for.
 *
 * @return array{0: User, 1: Application}
 */
function userWithApplication(bool $withKey = false): array
{
    $user = User::factory()->create();
    if ($withKey) {
        app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');
    }
    DefaultStages::seedFor($user);
    $user->profile()->create(['headline' => 'Staff Engineer', 'resume_text' => 'x']);

    $job = JobPosting::factory()->create(['title' => 'Staff Engineer', 'company' => 'Acme']);
    $application = app(ApplicationService::class)->createFromJob($user, $job);

    return [$user, $application];
}

/**
 * Insert N letter-purpose ai_calls rows for the user, dated now (their tz).
 */
function seedLetterCalls(User $user, int $count): void
{
    foreach (range(1, $count) as $ignored) {
        AiCall::create([
            'user_id' => $user->id,
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-8',
            'endpoint' => 'messages',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_cents' => 0,
            'purpose' => 'letter',
            'status' => 'ok',
        ]);
    }
}

// --- generate ---------------------------------------------------------------

it('creates the cover letter and dispatches the generation job', function () {
    Queue::fake();
    [$user, $application] = userWithApplication();

    actingAs($user);
    letterApi()->postJson("/api/applications/{$application->id}/cover-letter/generate", [
        'tone' => 'warm',
        'variants' => ['story_led', 'results_led'],
    ])->assertStatus(202)->assertJsonPath('data.application_id', $application->id);

    $coverLetter = CoverLetter::withoutGlobalScopes()->where('application_id', $application->id)->first();
    expect($coverLetter)->not->toBeNull();
    expect($coverLetter->user_id)->toBe($user->id);

    Queue::assertPushed(GenerateLetterJob::class, fn ($job) => $job->coverLetterId === $coverLetter->id
        && $job->params['variants'] === ['story_led', 'results_led']);
});

it('is idempotent — a second generate reuses the one cover letter row', function () {
    Queue::fake();
    [$user, $application] = userWithApplication();

    actingAs($user);
    letterApi()->postJson("/api/applications/{$application->id}/cover-letter/generate")->assertStatus(202);
    letterApi()->postJson("/api/applications/{$application->id}/cover-letter/generate")->assertStatus(202);

    expect(CoverLetter::withoutGlobalScopes()->where('application_id', $application->id)->count())->toBe(1);
    Queue::assertPushed(GenerateLetterJob::class, 2);
});

// --- show -------------------------------------------------------------------

it('shows the cover letter with its versions', function () {
    [$user, $application] = userWithApplication();
    $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);
    CoverLetterVersion::create(['user_id' => $user->id, 'cover_letter_id' => $letter->id, 'content_md' => 'A', 'variant_label' => 'story_led']);
    CoverLetterVersion::create(['user_id' => $user->id, 'cover_letter_id' => $letter->id, 'content_md' => 'B', 'variant_label' => 'results_led']);

    actingAs($user);
    letterApi()->getJson("/api/applications/{$application->id}/cover-letter")
        ->assertOk()
        ->assertJsonPath('data.id', $letter->id)
        ->assertJsonCount(2, 'data.versions');
});

it('returns null when the application has no cover letter', function () {
    [$user, $application] = userWithApplication();

    actingAs($user);
    letterApi()->getJson("/api/applications/{$application->id}/cover-letter")
        ->assertOk()->assertJsonPath('data', null);
});

// --- set active version -----------------------------------------------------

it('sets the active version to one that belongs to the letter', function () {
    [$user, $application] = userWithApplication();
    $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);
    $version = CoverLetterVersion::create(['user_id' => $user->id, 'cover_letter_id' => $letter->id, 'content_md' => 'A', 'variant_label' => 'story_led']);

    actingAs($user);
    letterApi()->patchJson("/api/cover-letters/{$letter->id}/active-version", ['active_version_id' => $version->id])
        ->assertOk();

    expect($letter->fresh()->active_version_id)->toBe($version->id);
});

it('rejects an active version from a different letter', function () {
    [$user, $application] = userWithApplication();
    $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);

    $otherJob = JobPosting::factory()->create();
    $otherApp = app(ApplicationService::class)->createFromJob($user, $otherJob);
    $otherLetter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $otherApp->id]);
    $foreignVersion = CoverLetterVersion::create(['user_id' => $user->id, 'cover_letter_id' => $otherLetter->id, 'content_md' => 'X', 'variant_label' => 'story_led']);

    actingAs($user);
    letterApi()->patchJson("/api/cover-letters/{$letter->id}/active-version", ['active_version_id' => $foreignVersion->id])
        ->assertStatus(422);
});

// --- edit a version ---------------------------------------------------------

it('saves edits to a version body', function () {
    [$user, $application] = userWithApplication();
    $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);
    $version = CoverLetterVersion::create(['user_id' => $user->id, 'cover_letter_id' => $letter->id, 'content_md' => 'old', 'variant_label' => 'story_led']);

    actingAs($user);
    letterApi()->patchJson("/api/cover-letter-versions/{$version->id}", ['content_md' => 'edited body'])
        ->assertOk()->assertJsonPath('data.content_md', 'edited body');

    expect($version->fresh()->content_md)->toBe('edited body');
});

// --- regenerate a paragraph -------------------------------------------------

it('regenerates a single paragraph and returns the rewritten text', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'model' => 'claude-opus-4-8',
        'content' => [['type' => 'text', 'text' => 'A crisper paragraph.']],
        'usage' => ['input_tokens' => 200, 'output_tokens' => 60],
    ])]);

    [$user, $application] = userWithApplication(withKey: true);
    $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);
    $version = CoverLetterVersion::create(['user_id' => $user->id, 'cover_letter_id' => $letter->id, 'content_md' => 'Some paragraph.', 'variant_label' => 'story_led', 'generation_params' => ['tone' => 'warm']]);

    actingAs($user);
    letterApi()->postJson("/api/cover-letters/{$letter->id}/regenerate-paragraph", [
        'version_id' => $version->id,
        'paragraph' => 'Some paragraph.',
    ])->assertOk()->assertJsonPath('data.text', 'A crisper paragraph.');

    expect(AiCall::withoutGlobalScopes()->where('purpose', 'letter_paragraph')->exists())->toBeTrue();
});

// --- soft cap (T-56) --------------------------------------------------------

it('adds a nudge once the daily soft cap is reached', function () {
    Queue::fake();
    [$user, $application] = userWithApplication();

    // Five letter-related calls already made today.
    seedLetterCalls($user, 5);

    actingAs($user);
    $response = letterApi()->postJson("/api/applications/{$application->id}/cover-letter/generate")
        ->assertStatus(202);

    expect($response->json('nudge'))->toContain('editing an existing draft');
});

it('has no nudge below the soft cap', function () {
    Queue::fake();
    [$user, $application] = userWithApplication();
    seedLetterCalls($user, 2);

    actingAs($user);
    $response = letterApi()->postJson("/api/applications/{$application->id}/cover-letter/generate")
        ->assertStatus(202);

    expect($response->json('nudge'))->toBeNull();
});

// --- cross-user isolation ---------------------------------------------------

it('does not let user B see or generate on user A\'s cover letter', function () {
    Queue::fake();
    [, $application] = userWithApplication();
    $userB = User::factory()->create();

    actingAs($userB);
    letterApi()->getJson("/api/applications/{$application->id}/cover-letter")->assertNotFound();
    letterApi()->postJson("/api/applications/{$application->id}/cover-letter/generate")->assertNotFound();
});

it('does not let user B touch user A\'s letter or version', function () {
    [$userA, $application] = userWithApplication();
    $letter = CoverLetter::create(['user_id' => $userA->id, 'application_id' => $application->id]);
    $version = CoverLetterVersion::create(['user_id' => $userA->id, 'cover_letter_id' => $letter->id, 'content_md' => 'A', 'variant_label' => 'story_led']);

    $userB = User::factory()->create();
    actingAs($userB);

    letterApi()->patchJson("/api/cover-letters/{$letter->id}/active-version", ['active_version_id' => $version->id])->assertNotFound();
    letterApi()->patchJson("/api/cover-letter-versions/{$version->id}", ['content_md' => 'hacked'])->assertNotFound();

    expect($version->fresh()->content_md)->toBe('A');
});
