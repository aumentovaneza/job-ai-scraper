<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/ai-key')->assertUnauthorized();
        $this->postJson('/api/ai-key', ['provider' => 'anthropic', 'key' => 'sk-ant-x'])->assertUnauthorized();
    }

    public function test_store_verifies_anthropic_key_and_never_returns_it_raw(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200)]);

        $user = User::factory()->create();
        $rawKey = 'sk-ant-live-key-abcdef123456';

        $response = $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/ai-key', ['provider' => 'anthropic', 'key' => $rawKey]);

        $response->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('provider', 'anthropic')
            ->assertJsonPath('key.has_key', true);

        // The raw key must never appear in the response body.
        $this->assertStringNotContainsString($rawKey, $response->getContent());
        $this->assertNotNull($user->fresh()->anthropic_key_verified_at);
    }

    public function test_store_verifies_openai_key(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/ai-key', ['provider' => 'openai', 'key' => 'sk-openai-live-abcdef123456'])
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('provider', 'openai');

        $this->assertNotNull($user->fresh()->openai_key_verified_at);
    }

    public function test_store_reports_unverified_when_key_is_rejected(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid']], 401)]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/ai-key', ['provider' => 'anthropic', 'key' => 'sk-ant-bad-key-abcdef12'])
            ->assertOk()
            ->assertJsonPath('verified', false);

        $this->assertNull($user->fresh()->anthropic_key_verified_at);
    }

    public function test_store_validates_key_presence_and_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/ai-key', ['provider' => 'anthropic', 'key' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('key');

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/ai-key', ['provider' => 'gemini', 'key' => 'sk-ant-valid-key-abcdef12'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('provider');
    }

    public function test_destroy_removes_the_key_for_a_provider(): void
    {
        $user = User::factory()->create();
        app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-remove-abcdef123456');

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->deleteJson('/api/ai-key', ['provider' => 'anthropic'])
            ->assertOk();

        $this->assertNull($user->fresh()->encrypted_anthropic_key);
    }

    public function test_set_active_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->putJson('/api/ai-provider', ['provider' => 'openai'])
            ->assertOk()
            ->assertJsonPath('ai_provider', 'openai');

        $this->assertSame('openai', $user->fresh()->ai_provider);
    }

    public function test_user_cannot_see_another_users_key(): void
    {
        $owner = User::factory()->create();
        app(AiKeyService::class)->store($owner, AiProvider::Anthropic, 'sk-ant-owner-secret-123456');

        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/ai-key?provider=anthropic')
            ->assertOk()
            ->assertJsonPath('key.has_key', false)
            ->assertJsonPath('key.masked', null);
    }
}
