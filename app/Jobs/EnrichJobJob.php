<?php

namespace App\Jobs;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AiException;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI enrichment of a canonical posting (T-31): runs Claude over the scraped JD
 * with the `enrich_job.v1` prompt and stores a structured analysis
 * (required/nice-to-have skills, seniority, remote_type, salary_band, red_flags,
 * one_line_summary) on JobPosting.enrichment. Kicked off by DedupeJobJob when a
 * brand-new posting lands (PLAN.md §5 Phase 3).
 *
 * JobPosting is the shared canonical record and isn't user-scoped, but the Claude
 * call is BYOK: it's funded by the user whose job source surfaced this posting,
 * so we carry that user_id explicitly (workers are unauthenticated). The result
 * is user-agnostic — enriching a JD yields the same skills/seniority for everyone
 * — so it's cached once on the shared posting for all users to reuse.
 *
 * Idempotent: safe to retry. If the posting already carries enrichment from this
 * prompt version we skip the (paid) call rather than re-enrich.
 */
class EnrichJobJob implements ShouldQueue
{
    use Queueable;

    private const PROMPT_VERSION = 'enrich_job.v1';

    public int $tries = 3;

    public function __construct(
        public readonly int $jobPostingId,
        public readonly int $userId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('ai');
    }

    public function handle(AiClientFactory $factory): void
    {
        $posting = JobPosting::find($this->jobPostingId);

        if ($posting === null || blank($posting->jd_text)) {
            return;
        }

        // Already enriched with the current prompt version — don't re-spend
        // (unless a manual re-run explicitly forces a fresh enrichment).
        if (! $this->force && ($posting->enrichment['prompt_version'] ?? null) === self::PROMPT_VERSION) {
            return;
        }

        // The scraping user funds the call; worker context is unauthenticated.
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $content = Prompt::render(self::PROMPT_VERSION, [
            'jd_text' => $posting->jd_text,
        ]);

        try {
            $response = $factory->forUser($user)->messages(
                [
                    'max_tokens' => 1024,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ],
                purpose: 'jd_enrichment',
                referenceType: JobPosting::class,
                referenceId: $posting->id,
                promptVersion: self::PROMPT_VERSION,
            );
        } catch (AiBudgetExceededException|AiException $e) {
            // The ledger already recorded the failure; surface it and stop. One
            // user's exhausted budget must not block the posting for everyone.
            Log::warning('Job enrichment failed', [
                'job_posting_id' => $posting->id,
                'user_id' => $this->userId,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $enrichment = $this->parseEnrichment($response->text);

        if ($enrichment === null) {
            Log::warning('Job enrichment response was not valid JSON', [
                'job_posting_id' => $posting->id,
            ]);

            return;
        }

        $enrichment['prompt_version'] = self::PROMPT_VERSION;
        $enrichment['generated_at'] = now()->toIso8601String();

        $posting->forceFill(['enrichment' => $enrichment])->save();

        $this->dispatchMatching($posting);
    }

    /**
     * Score the freshly-enriched posting for every user who tracks it — one
     * MatchJobToProfileJob per distinct owner of a source that surfaced it (T-32).
     * Runs on the raw tables because JobSource carries a BelongsToUser scope that
     * would filter to nothing in this unauthenticated worker context.
     */
    private function dispatchMatching(JobPosting $posting): void
    {
        $userIds = DB::table('job_source_hits')
            ->join('job_sources', 'job_sources.id', '=', 'job_source_hits.job_source_id')
            ->where('job_source_hits.job_posting_id', $posting->id)
            ->distinct()
            ->pluck('job_sources.user_id');

        foreach ($userIds as $userId) {
            // A forced re-enrichment forces the follow-on re-score too, so the
            // cached score doesn't shadow the fresh enrichment.
            MatchJobToProfileJob::dispatch((int) $userId, $posting->id, $this->force);
        }
    }

    /**
     * Parse the model's JSON, tolerating an accidental prose preamble or code
     * fence by extracting the first balanced object.
     */
    private function parseEnrichment(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
