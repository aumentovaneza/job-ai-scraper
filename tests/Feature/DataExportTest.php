<?php

use App\Models\Application;
use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use App\Models\JobPosting;
use App\Models\Snippet;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\DefaultStages;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

function exportApiRequest()
{
    return test()->withHeader('Referer', config('app.url'));
}

/**
 * A user with a tracked application (contact, note, follow-up) and a
 * generated cover letter, plus a snippet — enough to exercise every
 * top-level export key.
 *
 * @return array{0: User, 1: Application, 2: JobPosting}
 */
function exportableAccount(): array
{
    $user = User::factory()->create();
    DefaultStages::seedFor($user);

    $job = JobPosting::factory()->create(['company' => 'Acme Corp']);
    $application = app(ApplicationService::class)->createFromJob($user, $job);
    app(ApplicationService::class)->addNote($application, 'Applied via referral');
    app(ApplicationService::class)->addContact($application, ['name' => 'Jane Recruiter']);

    $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);
    CoverLetterVersion::create([
        'user_id' => $user->id,
        'cover_letter_id' => $letter->id,
        'content_md' => 'Dear team',
        'variant_label' => 'story_led',
    ]);

    Snippet::create(['user_id' => $user->id, 'label' => 'Closing', 'content_md' => 'Best regards,', 'position' => 1]);

    return [$user, $application->fresh(), $job];
}

it('requires authentication for the export endpoint', function () {
    getJson('/api/export')->assertUnauthorized();
});

it('exports the authenticated user\'s data as a downloadable JSON file', function () {
    [$user, $application, $job] = exportableAccount();

    actingAs($user);
    $response = exportApiRequest()->get('/api/export')->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/json');
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment; filename="jobscope-export-'.now()->format('Y-m-d'));

    $data = $response->json();

    expect($data['user']['email'])->toBe($user->email);
    expect($data['applications'])->toHaveCount(1);
    expect($data['applications'][0]['id'])->toBe($application->id);
    expect($data['applications'][0]['job_posting']['id'])->toBe($job->id);
    expect($data['applications'][0]['contacts'])->toHaveCount(1);
    expect($data['applications'][0]['events'])->not->toBeEmpty();
    expect($data['applications'][0]['cover_letter']['versions'])->toHaveCount(1);

    expect($data['cover_letters'])->toHaveCount(1);
    expect($data['cover_letters'][0]['versions'])->toHaveCount(1);

    expect($data['jobs'])->toHaveCount(1);
    expect($data['jobs'][0]['id'])->toBe($job->id);

    expect($data['snippets'])->toHaveCount(1);
    expect($data['snippets'][0]['label'])->toBe('Closing');
});

it('does not leak another user\'s applications, letters, jobs, or snippets', function () {
    [$owner] = exportableAccount();
    $viewer = User::factory()->create();
    DefaultStages::seedFor($viewer);

    actingAs($viewer);
    $data = exportApiRequest()->get('/api/export')->assertOk()->json();

    expect($data['user']['email'])->toBe($viewer->email);
    expect($data['applications'])->toBe([]);
    expect($data['cover_letters'])->toBe([]);
    expect($data['jobs'])->toBe([]);
    expect($data['snippets'])->toBe([]);
});
