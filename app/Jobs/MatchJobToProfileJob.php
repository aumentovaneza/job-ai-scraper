<?php

namespace App\Jobs;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AiException;
use App\Models\JobPosting;
use App\Models\MatchScore;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Score how well one user's profile fits one enriched posting (T-32): calls
 * Claude with `match_score.v1` using the profile + the posting's enrichment and
 * upserts a 0–100 score with reasoning/strengths/gaps into match_scores.
 *
 * Fanned out by EnrichJobJob once a posting is enriched — one job per user who
 * tracks that posting. MatchScore is user-scoped, but this runs in an
 * unauthenticated worker, so user_id is set explicitly and lookups bypass the
 * BelongsToUser scope.
 *
 * T-34 result cache: the score is fingerprinted by hash(jd_text, profile_version,
 * prompt_version). If an existing score for this (user, posting) already carries
 * that fingerprint, the inputs haven't changed and we skip the (paid) Claude call.
 */
class MatchJobToProfileJob implements ShouldQueue
{
    use Queueable;

    private const PROMPT_VERSION = 'match_score.v1';

    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
        public readonly int $jobPostingId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('ai');
    }

    public function handle(AiClientFactory $factory): void
    {
        $user = User::find($this->userId);
        $posting = JobPosting::find($this->jobPostingId);

        if ($user === null || $posting === null || blank($posting->jd_text)) {
            return;
        }

        // Worker context is unauthenticated; resolve the profile without the scope.
        $profile = Profile::withoutGlobalScopes()->where('user_id', $user->id)->first();

        if ($profile === null || blank($profile->resume_text)) {
            return; // nothing to score against yet — user hasn't finished onboarding
        }

        $inputHash = $this->inputHash($posting, $profile);

        $existing = MatchScore::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('job_posting_id', $posting->id)
            ->first();

        // T-34: inputs unchanged since the last score — skip the Claude call
        // (unless a manual re-run explicitly forces a fresh score).
        if (! $this->force && $existing !== null && $existing->input_hash === $inputHash) {
            return;
        }

        $content = Prompt::render(self::PROMPT_VERSION, [
            'headline' => $profile->headline ?? '',
            'summary' => $profile->summary ?? '',
            'target_roles' => $this->stringifyList($profile->target_roles),
            'target_locations' => $this->stringifyList($profile->target_locations),
            'target_comp' => $profile->target_comp_min_cents !== null
                ? (string) intdiv($profile->target_comp_min_cents, 100)
                : 'not specified',
            'resume_text' => $profile->resume_text,
            'job_title' => $posting->title ?? '',
            'job_company' => $posting->company ?? '',
            'job_location' => $posting->location ?? '',
            'enrichment' => $this->stringifyEnrichment($posting->enrichment),
            'jd_text' => $posting->jd_text,
        ]);

        try {
            $response = $factory->forUser($user)->messages(
                [
                    'max_tokens' => 1024,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ],
                purpose: 'match_score',
                referenceType: JobPosting::class,
                referenceId: $posting->id,
                promptVersion: self::PROMPT_VERSION,
            );
        } catch (AiBudgetExceededException|AiException $e) {
            // Ledger already recorded the failure; one user's budget stops here only.
            Log::warning('Match scoring failed', [
                'user_id' => $user->id,
                'job_posting_id' => $posting->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $result = $this->parseScore($response->text);

        if ($result === null) {
            Log::warning('Match score response was not valid JSON', [
                'user_id' => $user->id,
                'job_posting_id' => $posting->id,
            ]);

            return;
        }

        MatchScore::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $user->id, 'job_posting_id' => $posting->id],
            [
                'score' => $this->clampScore($result['score'] ?? null),
                'reasoning' => is_string($result['reasoning'] ?? null) ? $result['reasoning'] : null,
                'strengths' => $this->stringList($result['strengths'] ?? null),
                'gaps' => $this->stringList($result['gaps'] ?? null),
                'prompt_version' => self::PROMPT_VERSION,
                'input_hash' => $inputHash,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * Cache fingerprint (T-34): hash of the JD, the profile version, and the
     * prompt version. profile_version is a content hash of the matching-relevant
     * profile fields, so any resume/target edit invalidates the cache.
     */
    private function inputHash(JobPosting $posting, Profile $profile): string
    {
        $profileVersion = hash('sha256', json_encode([
            $profile->headline,
            $profile->summary,
            $profile->resume_text,
            $profile->target_roles,
            $profile->target_locations,
            $profile->target_comp_min_cents,
        ]));

        return hash('sha256', implode('|', [
            (string) $posting->jd_text,
            $profileVersion,
            self::PROMPT_VERSION,
        ]));
    }

    /**
     * Parse the model's JSON, tolerating an accidental prose preamble or code
     * fence by extracting the first balanced object.
     */
    private function parseScore(string $text): ?array
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

    private function clampScore(mixed $score): ?int
    {
        if (! is_numeric($score)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $score)));
    }

    /**
     * Keep only string entries from a model-returned list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param  array<int, mixed>|null  $list
     */
    private function stringifyList(?array $list): string
    {
        if (empty($list)) {
            return 'not specified';
        }

        return implode(', ', array_filter($list, 'is_scalar'));
    }

    /**
     * @param  array<string, mixed>|null  $enrichment
     */
    private function stringifyEnrichment(?array $enrichment): string
    {
        if (empty($enrichment)) {
            return 'No structured enrichment available.';
        }

        // Drop bookkeeping keys the model doesn't need to see.
        unset($enrichment['prompt_version'], $enrichment['generated_at']);

        return (string) json_encode($enrichment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
