<?php

namespace Tests\Feature;

use App\Jobs\ExtractVoiceProfileJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_fresh_profile_reports_incomplete_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('onboarding.key_verified', false)
            ->assertJsonPath('onboarding.resume', false)
            ->assertJsonPath('onboarding.targets', false)
            ->assertJsonPath('onboarding.complete', false);
    }

    public function test_update_persists_targets(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->putJson('/api/profile', [
                'headline' => 'Senior Platform Engineer',
                'target_roles' => ['Platform Engineer', 'Backend Engineer'],
                'target_locations' => ['Remote'],
                'target_comp_min_cents' => 18000000,
            ])
            ->assertOk()
            ->assertJsonPath('onboarding.targets', true)
            ->assertJsonPath('profile.target_roles.0', 'Platform Engineer');

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'headline' => 'Senior Platform Engineer',
            'target_comp_min_cents' => 18000000,
        ]);
    }

    public function test_upload_resume_extracts_and_stores_text(): void
    {
        $user = User::factory()->create();
        $file = $this->docxUpload(['Jane Doe — Staff Engineer', 'Ten years building Laravel APIs.']);

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->post('/api/profile/resume', ['resume' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('onboarding.resume', true);

        $this->assertGreaterThan(0, $user->profile()->first()->resume_text ? mb_strlen($user->profile->first()->resume_text) : 0);
        $this->assertStringContainsString('Staff Engineer', $user->profile()->first()->resume_text);
    }

    public function test_upload_rejects_disallowed_file_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->post('/api/profile/resume', ['resume' => UploadedFile::fake()->create('resume.txt', 10, 'text/plain')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('resume');
    }

    public function test_extract_voice_requires_resume_and_verified_key(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        // No resume yet.
        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/profile/voice')
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_extract_voice_dispatches_job_when_ready(): void
    {
        Queue::fake();
        $user = User::factory()->create(['anthropic_key_verified_at' => now()]);
        $user->profile()->create(['resume_text' => 'Seasoned engineer who writes plainly.']);

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/profile/voice', ['writing_sample' => 'I build calm, reliable systems.'])
            ->assertStatus(202);

        Queue::assertPushed(ExtractVoiceProfileJob::class);
    }

    public function test_user_cannot_see_another_users_profile(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->create(['target_roles' => ['Secret Role'], 'headline' => 'Owner headline']);

        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('profile.target_roles', [])
            ->assertJsonPath('profile.headline', null);
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
