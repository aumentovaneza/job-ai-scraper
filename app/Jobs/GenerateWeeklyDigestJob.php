<?php

namespace App\Jobs;

use App\Exceptions\Ai\AiException;
use App\Mail\WeeklyDigestMail;
use App\Models\Application;
use App\Models\InsightSummary;
use App\Models\MatchScore;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\Prompt;
use App\Services\AnalyticsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds one user's weekly digest (Phase 6): computes their conversion analytics,
 * asks Claude to narrate the numbers (insights_summary.v1), persists the result as
 * an InsightSummary the dashboard reads, and emails the digest (top new matches +
 * summary). Dispatched per user by InsightsDigestCommand.
 *
 * The Claude narrative is best-effort — a budget stop or missing key never blocks
 * the email; the digest simply goes out with the numbers and no prose. Runs in an
 * unauthenticated worker, so every lookup bypasses the BelongsToUser scope and is
 * filtered by user_id explicitly.
 */
class GenerateWeeklyDigestJob implements ShouldQueue
{
    use Queueable;

    private const PROMPT_VERSION = 'insights_summary.v1';

    /** Job postings first seen within this many days count as "new" matches. */
    private const NEW_MATCH_DAYS = 7;

    public int $tries = 1;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue('ai');
    }

    public function handle(
        AnalyticsService $analytics,
        AiClientFactory $factory,
        AiKeyService $keys,
    ): void {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $overview = $analytics->overviewFor($user);
        $topMatches = $this->topNewMatches($user);

        // Nothing to say and nothing to surface — skip the digest entirely so we
        // don't spam an empty account.
        if (($overview['totals']['applied'] ?? 0) === 0 && $topMatches === []) {
            return;
        }

        $summaryMarkdown = $this->narrate($user, $overview, $factory, $keys);

        InsightSummary::create([
            'user_id' => $user->id,
            'summary_md' => $summaryMarkdown,
            'metrics' => $overview,
            'period_start' => now()->subDays(self::NEW_MATCH_DAYS),
            'period_end' => now(),
            'generated_at' => now(),
        ]);

        Mail::to($user->email)->send(new WeeklyDigestMail(
            user: $user,
            summaryMarkdown: $summaryMarkdown,
            totals: $overview['totals'],
            topMatches: $topMatches,
        ));
    }

    /**
     * Ask Claude to narrate the metrics. Only spends a call when there's real
     * history (at least one applied application); returns null on any failure so
     * the digest still ships.
     *
     * @param  array<string, mixed>  $overview
     */
    private function narrate(User $user, array $overview, AiClientFactory $factory, AiKeyService $keys): ?string
    {
        if (($overview['totals']['applied'] ?? 0) === 0) {
            return null;
        }

        $provider = $keys->activeProvider($user);
        if (! $keys->hasKey($user, $provider)) {
            return null;
        }

        $profile = Profile::withoutGlobalScopes()->where('user_id', $user->id)->first();

        $content = Prompt::render(self::PROMPT_VERSION, [
            'period' => 'the last '.self::NEW_MATCH_DAYS.' days',
            'candidate_headline' => $profile?->headline ?: 'not provided',
            'stats' => app(AnalyticsService::class)->statsBlock($overview),
        ]);

        try {
            $response = $factory->forUser($user)->messages(
                [
                    'max_tokens' => 600,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ],
                purpose: 'insights_summary',
                referenceType: InsightSummary::class,
                referenceId: null,
                promptVersion: self::PROMPT_VERSION,
            );
        } catch (AiException $e) {
            Log::warning('Weekly insights narration failed', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $text = trim($response->text);

        return $text !== '' ? $text : null;
    }

    /**
     * The highest-scoring recently-seen job matches the user hasn't applied to
     * yet — the "top new matches" block of the digest.
     *
     * @return list<array{title: string, company: string, score: ?int, apply_url: ?string}>
     */
    private function topNewMatches(User $user): array
    {
        $appliedJobIds = Application::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->pluck('job_posting_id')
            ->filter()
            ->all();

        $since = now()->subDays(self::NEW_MATCH_DAYS);

        return MatchScore::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('score')
            ->when($appliedJobIds !== [], fn ($q) => $q->whereNotIn('job_posting_id', $appliedJobIds))
            ->whereHas('jobPosting', fn ($q) => $q->where(function ($w) use ($since) {
                $w->where('first_seen_at', '>=', $since)->orWhere('created_at', '>=', $since);
            }))
            ->with('jobPosting')
            ->orderByDesc('score')
            ->limit(5)
            ->get()
            ->map(fn (MatchScore $m) => [
                'title' => $m->jobPosting?->title ?? 'Untitled role',
                'company' => $m->jobPosting?->company ?? 'Unknown',
                'score' => $m->score,
                'apply_url' => $m->jobPosting?->apply_url,
            ])
            ->all();
    }
}
