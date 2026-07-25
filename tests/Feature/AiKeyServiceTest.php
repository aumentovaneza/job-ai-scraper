<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private AiKeyService $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = app(AiKeyService::class);
    }

    public function test_stores_key_encrypted_and_retrieves_it(): void
    {
        $user = User::factory()->create();

        $this->keys->store($user, AiProvider::Anthropic, 'sk-ant-test-abcdef123456');

        // Persisted value is ciphertext, not the raw key.
        $this->assertNotSame('sk-ant-test-abcdef123456', $user->encrypted_anthropic_key);
        $this->assertSame('sk-ant-test-abcdef123456', Crypt::decryptString($user->encrypted_anthropic_key));
        $this->assertSame('sk-ant-test-abcdef123456', $this->keys->retrieve($user, AiProvider::Anthropic));
    }

    public function test_keys_are_isolated_per_provider(): void
    {
        $user = User::factory()->create();

        $this->keys->store($user, AiProvider::Anthropic, 'sk-ant-anthropic-key-123456');
        $this->keys->store($user, AiProvider::OpenAi, 'sk-openai-key-abcdef123456');

        $this->assertSame('sk-ant-anthropic-key-123456', $this->keys->retrieve($user, AiProvider::Anthropic));
        $this->assertSame('sk-openai-key-abcdef123456', $this->keys->retrieve($user, AiProvider::OpenAi));
        $this->assertNotNull($user->encrypted_openai_key);
    }

    public function test_masks_key_without_revealing_the_middle(): void
    {
        $user = User::factory()->create();
        $this->keys->store($user, AiProvider::Anthropic, 'sk-ant-secretmiddle-9999');

        $masked = $this->keys->masked($user, AiProvider::Anthropic);

        $this->assertNotNull($masked);
        $this->assertStringNotContainsString('secretmiddle', $masked);
        $this->assertStringStartsWith('sk-ant-s', $masked);
        $this->assertStringEndsWith('9999', $masked);
    }

    public function test_verify_marks_anthropic_key_valid_on_successful_ping(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200)]);

        $user = User::factory()->create();
        $this->keys->store($user, AiProvider::Anthropic, 'sk-ant-valid-key-123456');

        $this->assertTrue($this->keys->verify($user, AiProvider::Anthropic));
        $this->assertNotNull($user->fresh()->anthropic_key_verified_at);
    }

    public function test_verify_marks_openai_key_valid_on_successful_ping(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $user = User::factory()->create();
        $this->keys->store($user, AiProvider::OpenAi, 'sk-openai-valid-key-123456');

        $this->assertTrue($this->keys->verify($user, AiProvider::OpenAi));
        $this->assertNotNull($user->fresh()->openai_key_verified_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'openai.com/v1/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer sk-openai-valid-key-123456'));
    }

    public function test_verify_fails_and_clears_stamp_on_rejected_key(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'invalid api key']], 401)]);

        $user = User::factory()->create();
        $this->keys->store($user, AiProvider::OpenAi, 'sk-openai-bad-key-1234567');

        $this->assertFalse($this->keys->verify($user, AiProvider::OpenAi));
        $this->assertNull($user->fresh()->openai_key_verified_at);
    }

    public function test_forget_removes_only_that_providers_key(): void
    {
        $user = User::factory()->create(['anthropic_key_verified_at' => now()]);
        $this->keys->store($user, AiProvider::Anthropic, 'sk-ant-remove-me-123456');
        $this->keys->store($user, AiProvider::OpenAi, 'sk-openai-keep-me-123456');

        $this->keys->forget($user, AiProvider::Anthropic);

        $fresh = $user->fresh();
        $this->assertNull($fresh->encrypted_anthropic_key);
        $this->assertNull($fresh->anthropic_key_verified_at);
        $this->assertFalse($this->keys->hasKey($fresh, AiProvider::Anthropic));
        // The other provider's key is untouched.
        $this->assertTrue($this->keys->hasKey($fresh, AiProvider::OpenAi));
    }

    public function test_active_provider_defaults_to_anthropic_and_can_be_switched(): void
    {
        $user = User::factory()->create();

        $this->assertSame(AiProvider::Anthropic, $this->keys->activeProvider($user));

        $this->keys->setActiveProvider($user, AiProvider::OpenAi);

        $this->assertSame(AiProvider::OpenAi, $this->keys->activeProvider($user->fresh()));
    }
}
