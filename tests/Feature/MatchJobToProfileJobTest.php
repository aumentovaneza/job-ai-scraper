<?php

namespace Tests\Feature;

use App\Jobs\MatchJobToProfileJob;
use App\Models\JobPosting;
use App\Models\MatchScore;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatchJobToProfileJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeScore(array $score): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-4-8',
            'content' => [['type' => 'text', 'text' => json_encode($score)]],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 250],
        ], 200)]);
    }

    private function userWithProfile(): User
    {
        $user = User::factory()->create();
        app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');
        $user->profile()->create([
            'headline' => 'Senior Backend Engineer',
            'summary' => 'Ten years of PHP and Laravel.',
            'resume_text' => 'Built payment systems in Laravel for a decade.',
            'target_roles' => ['Backend Engineer'],
            'target_locations' => ['Remote'],
            'target_comp_min_cents' => 15_000_000,
        ]);

        return $user;
    }

    private function enrichedPosting(): JobPosting
    {
        return JobPosting::factory()->create([
            'jd_text' => 'Senior Laravel engineer for a fintech platform.',
            'enrichment' => [
                'required_skills' => ['PHP', 'Laravel'],
                'seniority' => 'senior',
                'prompt_version' => 'enrich_job.v1',
            ],
        ]);
    }

    public function test_stores_a_user_scoped_score_and_records_the_call(): void
    {
        $this->fakeScore([
            'score' => 87,
            'reasoning' => 'Strong Laravel and fintech overlap.',
            'strengths' => ['Deep Laravel experience'],
            'gaps' => ['No explicit fintech domain mention'],
        ]);

        $user = $this->userWithProfile();
        $posting = $this->enrichedPosting();

        (new MatchJobToProfileJob($user->id, $posting->id))->handle(app(AiClientFactory::class));

        $score = MatchScore::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('job_posting_id', $posting->id)
            ->first();

        $this->assertNotNull($score);
        $this->assertSame(87, $score->score);
        $this->assertSame(['Deep Laravel experience'], $score->strengths);
        $this->assertSame('match_score.v1', $score->prompt_version);
        $this->assertNotNull($score->input_hash);
        $this->assertNotNull($score->computed_at);

        $this->assertDatabaseHas('ai_calls', [
            'user_id' => $user->id,
            'purpose' => 'match_score',
            'prompt_version' => 'match_score.v1',
            'reference_type' => JobPosting::class,
            'reference_id' => $posting->id,
            'status' => 'ok',
        ]);
    }

    public function test_clamps_out_of_range_scores(): void
    {
        $this->fakeScore(['score' => 140, 'reasoning' => 'x', 'strengths' => [], 'gaps' => []]);

        $user = $this->userWithProfile();
        $posting = $this->enrichedPosting();

        (new MatchJobToProfileJob($user->id, $posting->id))->handle(app(AiClientFactory::class));

        $this->assertSame(100, MatchScore::withoutGlobalScopes()->first()->score);
    }

    public function test_cache_skips_the_call_when_inputs_are_unchanged(): void
    {
        $this->fakeScore([
            'score' => 70,
            'reasoning' => 'ok',
            'strengths' => [],
            'gaps' => [],
        ]);

        $user = $this->userWithProfile();
        $posting = $this->enrichedPosting();

        // First run computes and stores the fingerprint.
        (new MatchJobToProfileJob($user->id, $posting->id))->handle(app(AiClientFactory::class));
        // Second run with the same profile + JD must not hit Claude again.
        (new MatchJobToProfileJob($user->id, $posting->id))->handle(app(AiClientFactory::class));

        Http::assertSentCount(1);
        $this->assertSame(1, MatchScore::withoutGlobalScopes()->count());
    }

    public function test_recomputes_when_the_profile_changes(): void
    {
        $this->fakeScore([
            'score' => 70,
            'reasoning' => 'ok',
            'strengths' => [],
            'gaps' => [],
        ]);

        $user = $this->userWithProfile();
        $posting = $this->enrichedPosting();

        (new MatchJobToProfileJob($user->id, $posting->id))->handle(app(AiClientFactory::class));

        // Edit the resume — the cache fingerprint should change, forcing a recompute.
        $user->profile->forceFill(['resume_text' => 'Now also a Vue.js expert.'])->save();

        (new MatchJobToProfileJob($user->id, $posting->id))->handle(app(AiClientFactory::class));

        Http::assertSentCount(2);
        $this->assertSame(1, MatchScore::withoutGlobalScopes()->count()); // still one row (updated)
    }

    public function test_no_op_when_the_user_has_no_profile(): void
    {
        Http::fake();
        $user = User::factory()->create();
        app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');
        $posting = $this->enrichedPosting();

        (new MatchJobToProfileJob($user->id, $posting->id))->handle(app(AiClientFactory::class));

        $this->assertSame(0, MatchScore::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }
}
