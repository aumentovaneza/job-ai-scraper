<?php

namespace Tests\Feature;

use App\Jobs\ExtractVoiceProfileJob;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AnthropicClientFactory;
use App\Services\Ai\AnthropicKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtractVoiceProfileJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_voice_profile_and_records_the_call(): void
    {
        $voice = [
            'tone' => ['direct', 'warm'],
            'formality' => 'professional',
            'summary' => 'Writes plainly and concretely.',
            'confidence' => 'medium',
        ];

        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-4-8',
            'content' => [['type' => 'text', 'text' => json_encode($voice)]],
            'usage' => ['input_tokens' => 500, 'output_tokens' => 200],
        ], 200)]);

        $user = User::factory()->create();
        app(AnthropicKeyService::class)->store($user, 'sk-ant-key-abcdef123456');
        $profile = $user->profile()->create(['resume_text' => 'Ten years of backend work.']);

        (new ExtractVoiceProfileJob($profile->id, 'I favor calm, reliable systems.'))
            ->handle(app(AnthropicClientFactory::class));

        $stored = $profile->fresh()->voice_profile;
        $this->assertSame('professional', $stored['formality']);
        $this->assertSame('extract_voice_profile.v1', $stored['prompt_version']);
        $this->assertArrayHasKey('generated_at', $stored);

        $this->assertDatabaseHas('ai_calls', [
            'user_id' => $user->id,
            'purpose' => 'voice_profile',
            'prompt_version' => 'extract_voice_profile.v1',
            'reference_type' => Profile::class,
            'reference_id' => $profile->id,
            'status' => 'ok',
        ]);
    }

    public function test_no_op_without_resume_text(): void
    {
        Http::fake();
        $user = User::factory()->create();
        app(AnthropicKeyService::class)->store($user, 'sk-ant-key-abcdef123456');
        $profile = $user->profile()->create(['resume_text' => null]);

        (new ExtractVoiceProfileJob($profile->id))
            ->handle(app(AnthropicClientFactory::class));

        $this->assertNull($profile->fresh()->voice_profile);
        Http::assertNothingSent();
    }
}
