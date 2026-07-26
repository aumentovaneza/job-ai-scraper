<?php

namespace Tests\Feature;

use App\Jobs\EnrichJobJob;
use App\Jobs\MatchJobToProfileJob;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReprocessJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Give $user a source that surfaces $posting, so the command's tracking-user
     * lookup finds them (mirrors the production job_source_hits fan-out).
     */
    private function track(User $user, JobPosting $posting): void
    {
        $source = JobSource::factory()->create(['user_id' => $user->id]);
        JobSourceHit::create([
            'job_posting_id' => $posting->id,
            'job_source_id' => $source->id,
            'source_url' => 'https://example.test/job',
            'first_seen_at' => now(),
        ]);
    }

    public function test_default_run_dispatches_forced_enrichment_for_a_posting(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $posting = JobPosting::factory()->create(['jd_text' => 'A role.']);
        $this->track($user, $posting);

        $this->artisan('jobs:reprocess', ['--posting' => $posting->id, '--force' => true])
            ->assertSuccessful();

        Queue::assertPushed(
            fn (EnrichJobJob $job) => $job->jobPostingId === $posting->id
                && $job->userId === $user->id
                && $job->force === true
        );
        Queue::assertNotPushed(MatchJobToProfileJob::class);
    }

    public function test_skips_postings_nobody_tracks(): void
    {
        Queue::fake();

        $posting = JobPosting::factory()->create(['jd_text' => 'Untracked role.']);

        $this->artisan('jobs:reprocess', ['--posting' => $posting->id])
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_score_only_dispatches_scoring_and_skips_enrichment(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $posting = JobPosting::factory()->create(['jd_text' => 'A role.']);
        $this->track($user, $posting);

        $this->artisan('jobs:reprocess', ['--score-only' => true, '--force' => true])
            ->assertSuccessful();

        Queue::assertNotPushed(EnrichJobJob::class);
        Queue::assertPushed(
            fn (MatchJobToProfileJob $job) => $job->jobPostingId === $posting->id
                && $job->userId === $user->id
                && $job->force === true
        );
    }

    public function test_user_filter_limits_scoring_to_one_user(): void
    {
        Queue::fake();

        $target = User::factory()->create();
        $other = User::factory()->create();
        $posting = JobPosting::factory()->create(['jd_text' => 'A role.']);
        $this->track($target, $posting);
        $this->track($other, $posting);

        $this->artisan('jobs:reprocess', ['--score-only' => true, '--user' => $target->id])
            ->assertSuccessful();

        Queue::assertPushed(MatchJobToProfileJob::class, 1);
        Queue::assertPushed(fn (MatchJobToProfileJob $job) => $job->userId === $target->id);
    }
}
