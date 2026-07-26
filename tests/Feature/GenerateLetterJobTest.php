<?php

use App\Jobs\GenerateLetterJob;
use App\Models\AiCall;
use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use App\Services\AnalyticsService;
use App\Services\ApplicationService;
use App\Services\CompanyContextService;
use App\Support\DefaultStages;
use Illuminate\Support\Facades\Http;

/**
 * A response payload for one faked Claude call with the given body text.
 */
function letterResponse(string $text, int $inputTokens = 800, int $outputTokens = 300): array
{
    return [
        'model' => 'claude-opus-4-8',
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
    ];
}

/**
 * A user with an Anthropic key + profile, an application, and an empty CoverLetter
 * ready for GenerateLetterJob.
 *
 * @return array{0: User, 1: CoverLetter}
 */
function letterSetup(): array
{
    $user = User::factory()->create();
    app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');
    DefaultStages::seedFor($user);
    $user->profile()->create([
        'headline' => 'Staff Engineer',
        'resume_text' => 'Led platform teams; shipped X to 10M users.',
        'voice_profile' => ['tone' => 'direct', 'traits' => ['concise']],
    ]);

    $job = JobPosting::factory()->create(['title' => 'Staff Engineer', 'company' => 'Acme', 'apply_url' => null]);
    $application = app(ApplicationService::class)->createFromJob($user, $job);

    $coverLetter = CoverLetter::create([
        'user_id' => $user->id,
        'application_id' => $application->id,
    ]);

    return [$user, $coverLetter];
}

function runLetterJob(int $coverLetterId, array $params = []): void
{
    (new GenerateLetterJob($coverLetterId, $params))->handle(
        app(AiClientFactory::class),
        app(CompanyContextService::class),
        app(AnalyticsService::class),
    );
}

it('creates one version per variant, sets the first active, and logs ai_calls', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(letterResponse('STORY letter body'))
        ->push(letterResponse('RESULTS letter body'))
        ->push(letterResponse('CULTURE letter body')),
    ]);

    [$user, $coverLetter] = letterSetup();

    runLetterJob($coverLetter->id);

    $versions = CoverLetterVersion::withoutGlobalScopes()
        ->where('cover_letter_id', $coverLetter->id)
        ->orderBy('id')
        ->get();

    expect($versions)->toHaveCount(3);
    expect($versions->pluck('variant_label')->all())
        ->toBe(['story_led', 'results_led', 'culture_led']);
    expect($versions->pluck('user_id')->unique()->all())->toBe([$user->id]);
    expect($versions->first()->content_md)->toBe('STORY letter body');

    // generation_params captures everything needed to reproduce the draft.
    $params = $versions->first()->generation_params;
    expect($params['prompt_version'])->toBe('letter_story_led.v2');
    expect($params['length_hint'])->toBe('medium');
    expect($params)->toHaveKey('company_context_used');

    $coverLetter->refresh();
    expect($coverLetter->active_version_id)->toBe($versions->first()->id);

    expect(AiCall::withoutGlobalScopes()->where('purpose', 'letter')->count())->toBe(3);
    expect(AiCall::withoutGlobalScopes()->where('purpose', 'letter')->where('prompt_version', 'letter_story_led.v2')->exists())->toBeTrue();
});

it('honors an explicit variants list', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(letterResponse('ONLY story'))]);

    [, $coverLetter] = letterSetup();

    runLetterJob($coverLetter->id, ['variants' => ['story_led']]);

    $versions = CoverLetterVersion::withoutGlobalScopes()
        ->where('cover_letter_id', $coverLetter->id)->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->variant_label)->toBe('story_led');
});

it('keeps already-generated variants when the spend cap stops the fan-out', function () {
    // Cost per call ≈ 200c; a 150c daily cap passes the first call then trips.
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(letterResponse('STORY letter body', inputTokens: 400_000, outputTokens: 0))
        ->push(letterResponse('RESULTS letter body', inputTokens: 400_000, outputTokens: 0))
        ->push(letterResponse('CULTURE letter body', inputTokens: 400_000, outputTokens: 0)),
    ]);

    [$user, $coverLetter] = letterSetup();
    $user->forceFill(['daily_ai_spend_cap_cents' => 150])->save();

    runLetterJob($coverLetter->id);

    $versions = CoverLetterVersion::withoutGlobalScopes()
        ->where('cover_letter_id', $coverLetter->id)->get();

    // First variant persisted before the cap tripped; the run didn't lose it.
    expect($versions)->toHaveCount(1);
    expect($versions->first()->variant_label)->toBe('story_led');

    $coverLetter->refresh();
    expect($coverLetter->active_version_id)->toBe($versions->first()->id);

    // Only the one completed call reached the ledger; the cap blocked the rest
    // pre-call (SpendTracker throws before any request or record).
    expect(AiCall::withoutGlobalScopes()->where('purpose', 'letter')->count())->toBe(1);
});
