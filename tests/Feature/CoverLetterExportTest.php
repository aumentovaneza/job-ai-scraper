<?php

use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\DefaultStages;

use function Pest\Laravel\actingAs;

function exportApi()
{
    return test()->withHeader('Referer', config('app.url'));
}

/**
 * A user with a cover-letter version ready to export.
 *
 * @return array{0: User, 1: CoverLetterVersion}
 */
function exportableVersion(): array
{
    $user = User::factory()->create();
    DefaultStages::seedFor($user);

    $job = JobPosting::factory()->create(['company' => 'Acme Corp']);
    $application = app(ApplicationService::class)->createFromJob($user, $job);
    $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);
    $version = CoverLetterVersion::create([
        'user_id' => $user->id,
        'cover_letter_id' => $letter->id,
        'content_md' => "# Dear team\n\nI am **excited** to apply.\n\nSincerely,",
        'variant_label' => 'story_led',
    ]);

    return [$user, $version];
}

it('exports markdown', function () {
    [$user, $version] = exportableVersion();

    actingAs($user);
    $response = exportApi()->get("/api/cover-letter-versions/{$version->id}/export?format=md")->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/markdown');
    expect($response->getContent())->toContain('**excited**');
});

it('exports plain text with markdown stripped', function () {
    [$user, $version] = exportableVersion();

    actingAs($user);
    $response = exportApi()->get("/api/cover-letter-versions/{$version->id}/export?format=txt")->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/plain');
    $body = $response->getContent();
    expect($body)->toContain('excited');
    expect($body)->not->toContain('**');
});

it('exports a non-empty docx', function () {
    [$user, $version] = exportableVersion();

    actingAs($user);
    $response = exportApi()->get("/api/cover-letter-versions/{$version->id}/export?format=docx")->assertOk();

    expect($response->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect(strlen($response->streamedContent()))->toBeGreaterThan(0);
});

it('exports a non-empty pdf', function () {
    [$user, $version] = exportableVersion();

    actingAs($user);
    $response = exportApi()->get("/api/cover-letter-versions/{$version->id}/export?format=pdf")->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect(strlen($response->getContent()))->toBeGreaterThan(0);
});

it('rejects an unsupported export format', function () {
    [$user, $version] = exportableVersion();

    actingAs($user);
    exportApi()->get("/api/cover-letter-versions/{$version->id}/export?format=exe")->assertStatus(422);
});

it('does not let user B export user A\'s version', function () {
    [, $version] = exportableVersion();
    $userB = User::factory()->create();

    actingAs($userB);
    exportApi()->get("/api/cover-letter-versions/{$version->id}/export?format=txt")->assertNotFound();
});
