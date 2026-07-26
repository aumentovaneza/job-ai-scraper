<?php

use App\Jobs\GenerateWeeklyDigestJob;
use App\Mail\WeeklyDigestMail;
use App\Models\ApplicationStage;
use App\Models\InsightSummary;
use App\Models\JobPosting;
use App\Models\MatchScore;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use App\Services\AnalyticsService;
use App\Services\ApplicationService;
use App\Support\DefaultStages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** Canned Claude completion for the narrative call. */
function digestResponse(string $text): array
{
    return [
        'model' => 'claude-opus-4-8',
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 500, 'output_tokens' => 120],
    ];
}

function digestUser(): User
{
    $user = User::factory()->create();
    app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');
    DefaultStages::seedFor($user);

    return $user;
}

function stageNamed(User $user, string $name)
{
    return ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $user->id)->where('name', $name)->firstOrFail();
}

it('stores a narrative summary and emails the digest', function () {
    Mail::fake();
    Http::fake(['api.anthropic.com/*' => Http::response(digestResponse('You applied to 1 role this week.'))]);

    $user = digestUser();
    $service = app(ApplicationService::class);

    // One applied application so there's history to narrate.
    $applied = $service->createFromJob($user, JobPosting::factory()->create());
    $service->moveToStage($applied->fresh(), stageNamed($user, 'Applied'));

    // A fresh, unapplied, high-scoring match to surface in "top new matches".
    $match = JobPosting::factory()->create(['title' => 'Staff Engineer', 'company' => 'Acme', 'first_seen_at' => now()]);
    MatchScore::create(['user_id' => $user->id, 'job_posting_id' => $match->id, 'score' => 92]);

    (new GenerateWeeklyDigestJob($user->id))->handle(
        app(AnalyticsService::class),
        app(AiClientFactory::class),
        app(AiKeyService::class),
    );

    $summary = InsightSummary::where('user_id', $user->id)->first();
    expect($summary)->not->toBeNull();
    expect($summary->summary_md)->toBe('You applied to 1 role this week.');
    expect($summary->metrics['totals']['applied'])->toBe(1);

    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) use ($user) {
        return $mail->hasTo($user->email)
            && count($mail->topMatches) === 1
            && $mail->topMatches[0]['company'] === 'Acme';
    });
});

it('still emails the digest when the narrative call fails', function () {
    Mail::fake();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

    $user = digestUser();
    $service = app(ApplicationService::class);
    $applied = $service->createFromJob($user, JobPosting::factory()->create());
    $service->moveToStage($applied->fresh(), stageNamed($user, 'Applied'));

    (new GenerateWeeklyDigestJob($user->id))->handle(
        app(AnalyticsService::class),
        app(AiClientFactory::class),
        app(AiKeyService::class),
    );

    $summary = InsightSummary::where('user_id', $user->id)->first();
    expect($summary)->not->toBeNull();
    expect($summary->summary_md)->toBeNull();

    Mail::assertSent(WeeklyDigestMail::class);
});

it('renders the digest email template without error', function () {
    $user = digestUser();

    $mail = new WeeklyDigestMail(
        user: $user,
        summaryMarkdown: "You applied to 3 roles.\n\nStory-led letters converted best.",
        totals: [
            'applied' => 3, 'responded' => 1, 'response_rate' => 0.33,
            'in_progress' => 2, 'offers' => 0, 'won' => 0, 'rejected' => 1,
            'interview_to_offer_rate' => null,
        ],
        topMatches: [
            ['title' => 'Staff Engineer', 'company' => 'Acme', 'score' => 92, 'apply_url' => 'https://acme.test/jobs/1'],
        ],
    );

    $html = $mail->render();

    expect($html)->toContain('Staff Engineer');
    expect($html)->toContain('Acme');
    expect($html)->toContain('Story-led letters converted best.');
    expect($mail->envelope()->subject)->toContain('1 new match');
});

it('skips empty accounts entirely', function () {
    Mail::fake();
    Http::fake();

    $user = digestUser(); // no applications, no matches

    (new GenerateWeeklyDigestJob($user->id))->handle(
        app(AnalyticsService::class),
        app(AiClientFactory::class),
        app(AiKeyService::class),
    );

    expect(InsightSummary::where('user_id', $user->id)->exists())->toBeFalse();
    Mail::assertNothingSent();
    Http::assertNothingSent();
});
