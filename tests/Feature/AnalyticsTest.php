<?php

use App\Models\Application;
use App\Models\ApplicationStage;
use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use App\Models\JobPosting;
use App\Models\MatchScore;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ApplicationService;
use App\Support\DefaultStages;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

function analyticsRequest()
{
    return test()->withHeader('Referer', config('app.url'));
}

/** A user with the default pipeline seeded. */
function analyticsUser(): User
{
    $user = User::factory()->create();
    DefaultStages::seedFor($user);

    return $user;
}

/** Resolve one of the user's stages by name. */
function stage(User $user, string $name): ApplicationStage
{
    return ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $user->id)
        ->where('name', $name)
        ->firstOrFail();
}

/** Create an application and walk it through the given stages in order. */
function applicationThrough(User $user, array $stageNames, ?string $variantSent = null): Application
{
    $service = app(ApplicationService::class);
    $job = JobPosting::factory()->create();
    $application = $service->createFromJob($user, $job);

    foreach ($stageNames as $name) {
        $service->moveToStage($application->fresh(), stage($user, $name));
    }

    if ($variantSent !== null) {
        $letter = CoverLetter::create(['user_id' => $user->id, 'application_id' => $application->id]);
        CoverLetterVersion::create([
            'user_id' => $user->id,
            'cover_letter_id' => $letter->id,
            'content_md' => 'Dear team',
            'variant_label' => $variantSent,
            'was_sent' => true,
        ]);
    }

    return $application->fresh();
}

// --- Auth -------------------------------------------------------------------

it('requires authentication for the analytics endpoint', function () {
    getJson('/api/analytics')->assertUnauthorized();
});

// --- Overview metrics -------------------------------------------------------

it('computes response, offer and conversion rates from the event log', function () {
    $user = analyticsUser();

    // Responded (reached Recruiter Reply), story-led letter sent.
    applicationThrough($user, ['Applied', 'Recruiter Reply'], variantSent: 'story_led');
    // Applied only — ghosted, results-led letter sent.
    applicationThrough($user, ['Applied'], variantSent: 'results_led');
    // Reached Offer — responded + reached offer.
    applicationThrough($user, ['Applied', 'Recruiter Reply', 'Phone Screen', 'Offer']);
    // Applied then Rejected — not a response.
    applicationThrough($user, ['Applied', 'Rejected']);

    $overview = app(AnalyticsService::class)->overviewFor($user);
    $totals = $overview['totals'];

    expect($totals['applied'])->toBe(4);
    expect($totals['responded'])->toBe(2);          // recruiter reply + offer
    expect($totals['response_rate'])->toBe(0.5);
    expect($totals['offers'])->toBe(1);
    expect($totals['rejected'])->toBe(1);
    // interview→offer = offers / responded = 1/2.
    expect($totals['interview_to_offer_rate'])->toBe(0.5);
});

it('breaks down response rate by cover-letter variant', function () {
    $user = analyticsUser();
    applicationThrough($user, ['Applied', 'Recruiter Reply'], variantSent: 'story_led');
    applicationThrough($user, ['Applied'], variantSent: 'results_led');

    $overview = app(AnalyticsService::class)->overviewFor($user);
    $byVariant = collect($overview['response_rate_by_variant'])->keyBy('variant');

    expect($byVariant['story_led']['response_rate'])->toBe(1.0);
    expect($byVariant['results_led']['response_rate'])->toBe(0.0);
});

it('tallies the gaps flagged across rejected applications', function () {
    $user = analyticsUser();

    $rejected = applicationThrough($user, ['Applied', 'Rejected']);
    MatchScore::create([
        'user_id' => $user->id,
        'job_posting_id' => $rejected->job_posting_id,
        'score' => 40,
        'gaps' => ['Kubernetes experience', 'People leadership'],
    ]);

    // A non-rejected app's gaps must not count toward the rejected tally.
    $active = applicationThrough($user, ['Applied', 'Recruiter Reply']);
    MatchScore::create([
        'user_id' => $user->id,
        'job_posting_id' => $active->job_posting_id,
        'score' => 80,
        'gaps' => ['Kubernetes experience'],
    ]);

    $overview = app(AnalyticsService::class)->overviewFor($user);
    $gaps = collect($overview['top_gaps'])->keyBy('gap');

    expect($gaps['Kubernetes experience']['count'])->toBe(1);
    expect($gaps['People leadership']['count'])->toBe(1);
});

it('records time spent in each stage', function () {
    $user = analyticsUser();
    $service = app(ApplicationService::class);
    $job = JobPosting::factory()->create();

    $this->travelTo(now()->subDays(10));
    $application = $service->createFromJob($user, $job);
    $service->moveToStage($application->fresh(), stage($user, 'Applied'));

    $this->travelTo(now()->addDays(4));
    $service->moveToStage($application->fresh(), stage($user, 'Recruiter Reply'));
    $this->travelBack();

    $overview = app(AnalyticsService::class)->overviewFor($user);
    $applied = collect($overview['time_in_stage'])->firstWhere('name', 'Applied');

    expect($applied)->not->toBeNull();
    expect($applied['avg_days'])->toBeGreaterThanOrEqual(3.5);
});

// --- Endpoint ---------------------------------------------------------------

it('returns the overview payload for the authenticated user', function () {
    $user = analyticsUser();
    applicationThrough($user, ['Applied', 'Recruiter Reply']);
    actingAs($user);

    analyticsRequest()->getJson('/api/analytics')
        ->assertOk()
        ->assertJsonPath('data.totals.applied', 1)
        ->assertJsonPath('data.totals.responded', 1)
        ->assertJsonStructure([
            'data' => ['totals', 'response_rate_by_source', 'response_rate_by_variant', 'time_in_stage', 'top_gaps'],
            'summary',
        ]);
});

it('scopes analytics to the requesting user', function () {
    $me = analyticsUser();
    $other = analyticsUser();
    applicationThrough($other, ['Applied', 'Recruiter Reply']);
    actingAs($me);

    analyticsRequest()->getJson('/api/analytics')
        ->assertOk()
        ->assertJsonPath('data.totals.applied', 0);
});

// --- Priors (feedback loop) -------------------------------------------------

it('returns null priors until there is enough history', function () {
    $user = analyticsUser();
    applicationThrough($user, ['Applied'], variantSent: 'story_led');

    expect(app(AnalyticsService::class)->priorsFor($user))->toBeNull();
});

it('recommends the best-converting variant once there is enough history', function () {
    $user = analyticsUser();
    // Two story-led that both responded, one results-led that didn't.
    applicationThrough($user, ['Applied', 'Recruiter Reply'], variantSent: 'story_led');
    applicationThrough($user, ['Applied', 'Recruiter Reply'], variantSent: 'story_led');
    applicationThrough($user, ['Applied'], variantSent: 'results_led');

    $priors = app(AnalyticsService::class)->priorsFor($user);

    expect($priors)->not->toBeNull();
    expect($priors['best_variant']['variant'])->toBe('story_led');

    $preamble = app(AnalyticsService::class)->priorsPreamble($priors);
    expect($preamble)->toContain('Story Led');
});
