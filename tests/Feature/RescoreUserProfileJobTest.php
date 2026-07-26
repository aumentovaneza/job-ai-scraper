<?php

namespace Tests\Feature;

use App\Jobs\MatchJobToProfileJob;
use App\Jobs\RescoreUserProfileJob;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class RescoreUserProfileJobTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_fans_out_scoring_for_enriched_tracked_postings_only(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $enriched = JobPosting::factory()->create([
            'jd_text' => 'A role.',
            'enrichment' => ['prompt_version' => 'enrich_job.v1', 'seniority' => 'senior'],
        ]);
        $notEnriched = JobPosting::factory()->create(['jd_text' => 'Another role.', 'enrichment' => null]);

        $this->track($user, $enriched);
        $this->track($user, $notEnriched);

        (new RescoreUserProfileJob($user->id))->handle();

        // Only the enriched posting can be scored.
        Queue::assertPushed(MatchJobToProfileJob::class, 1);
        Queue::assertPushed(
            fn (MatchJobToProfileJob $job) => $job->userId === $user->id
                && $job->jobPostingId === $enriched->id
                && $job->force === false
        );
    }

    public function test_profile_update_triggers_rescore_on_relevant_change(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->putJson('/api/profile', ['headline' => 'Staff Engineer'])
            ->assertOk();

        Queue::assertPushed(
            fn (RescoreUserProfileJob $job) => $job->userId === $user->id
        );
    }

    public function test_profile_update_with_no_change_does_not_rescore(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->profile()->create(['headline' => 'Staff Engineer']);

        // Re-submit the same value: nothing matching-relevant changed.
        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->putJson('/api/profile', ['headline' => 'Staff Engineer'])
            ->assertOk();

        Queue::assertNotPushed(RescoreUserProfileJob::class);
    }

    public function test_resume_upload_triggers_rescore(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->post(
                '/api/profile/resume',
                ['resume' => $this->docxUpload(['Jane Doe — Staff Engineer', 'Ten years of Laravel.'])],
                ['Accept' => 'application/json'],
            )
            ->assertOk();

        Queue::assertPushed(
            fn (RescoreUserProfileJob $job) => $job->userId === $user->id
        );
    }

    private function docxUpload(array $paragraphs): UploadedFile
    {
        $word = new PhpWord;
        $section = $word->addSection();
        foreach ($paragraphs as $p) {
            $section->addText($p);
        }
        $path = tempnam(sys_get_temp_dir(), 'resume').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return new UploadedFile(
            $path,
            'resume.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true, // test mode: skip is_uploaded_file()
        );
    }
}
