<?php

namespace Tests\Feature;

use App\Jobs\EnrichJobJob;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichJobJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEnrichment(array $enrichment): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-4-8',
            'content' => [['type' => 'text', 'text' => json_encode($enrichment)]],
            'usage' => ['input_tokens' => 800, 'output_tokens' => 300],
        ], 200)]);
    }

    private function userWithKey(): User
    {
        $user = User::factory()->create();
        app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');

        return $user;
    }

    public function test_stores_enrichment_and_records_the_call(): void
    {
        $enrichment = [
            'required_skills' => ['PHP', 'Laravel'],
            'nice_to_have_skills' => ['Vue'],
            'seniority' => 'senior',
            'remote_type' => 'remote',
            'salary_band' => ['min' => 120000, 'max' => 160000, 'currency' => 'USD', 'period' => 'year'],
            'red_flags' => [],
            'one_line_summary' => 'Senior backend engineer for a fintech platform.',
        ];
        $this->fakeEnrichment($enrichment);

        $user = $this->userWithKey();
        $posting = JobPosting::factory()->create([
            'jd_text' => 'We are hiring a senior Laravel engineer to build our platform.',
            'enrichment' => null,
        ]);

        (new EnrichJobJob($posting->id, $user->id))->handle(app(AiClientFactory::class));

        $stored = $posting->fresh()->enrichment;
        $this->assertSame('senior', $stored['seniority']);
        $this->assertSame(['PHP', 'Laravel'], $stored['required_skills']);
        $this->assertSame('enrich_job.v1', $stored['prompt_version']);
        $this->assertArrayHasKey('generated_at', $stored);

        $this->assertDatabaseHas('ai_calls', [
            'user_id' => $user->id,
            'purpose' => 'jd_enrichment',
            'prompt_version' => 'enrich_job.v1',
            'reference_type' => JobPosting::class,
            'reference_id' => $posting->id,
            'status' => 'ok',
        ]);
    }

    public function test_no_op_without_jd_text(): void
    {
        Http::fake();
        $user = $this->userWithKey();
        $posting = JobPosting::factory()->create(['jd_text' => null, 'enrichment' => null]);

        (new EnrichJobJob($posting->id, $user->id))->handle(app(AiClientFactory::class));

        $this->assertNull($posting->fresh()->enrichment);
        Http::assertNothingSent();
    }

    public function test_skips_when_already_enriched_with_same_prompt_version(): void
    {
        Http::fake();
        $user = $this->userWithKey();
        $posting = JobPosting::factory()->create([
            'jd_text' => 'Some job description.',
            'enrichment' => ['seniority' => 'mid', 'prompt_version' => 'enrich_job.v1'],
        ]);

        (new EnrichJobJob($posting->id, $user->id))->handle(app(AiClientFactory::class));

        // Untouched: no second (paid) call, original enrichment preserved.
        Http::assertNothingSent();
        $this->assertSame('mid', $posting->fresh()->enrichment['seniority']);
    }
}
