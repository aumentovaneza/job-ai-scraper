<?php

namespace Tests\Feature;

use App\Jobs\EnrichJobJob;
use App\Jobs\MatchJobToProfileJob;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobPostingActionTest extends TestCase
{
    use RefreshDatabase;

    /** A user ready to score: verified key + a resume to score against. */
    private function readyUser(): User
    {
        $user = User::factory()->create(['anthropic_key_verified_at' => now()]);
        $user->profile()->create(['resume_text' => 'Seasoned engineer.']);

        return $user;
    }

    public function test_rescore_dispatches_forced_scoring_for_the_caller(): void
    {
        Queue::fake();

        $user = $this->readyUser();
        $posting = JobPosting::factory()->create(['jd_text' => 'A role.']);

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson("/api/jobs/{$posting->id}/rescore")
            ->assertStatus(202);

        Queue::assertPushed(
            fn (MatchJobToProfileJob $job) => $job->userId === $user->id
                && $job->jobPostingId === $posting->id
                && $job->force === true
        );
    }

    public function test_rescore_requires_a_resume_and_verified_key(): void
    {
        Queue::fake();

        // No resume, no verified key.
        $user = User::factory()->create();
        $posting = JobPosting::factory()->create(['jd_text' => 'A role.']);

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson("/api/jobs/{$posting->id}/rescore")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_enrich_dispatches_forced_enrichment(): void
    {
        Queue::fake();

        $user = $this->readyUser();
        $posting = JobPosting::factory()->create(['jd_text' => 'A role.']);

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson("/api/jobs/{$posting->id}/enrich")
            ->assertStatus(202);

        Queue::assertPushed(
            fn (EnrichJobJob $job) => $job->jobPostingId === $posting->id
                && $job->userId === $user->id
                && $job->force === true
        );
    }

    public function test_actions_require_authentication(): void
    {
        $posting = JobPosting::factory()->create(['jd_text' => 'A role.']);

        $this->postJson("/api/jobs/{$posting->id}/rescore")->assertUnauthorized();
    }
}
