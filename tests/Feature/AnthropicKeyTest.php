<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AnthropicKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/anthropic-key')->assertUnauthorized();
        $this->postJson('/api/anthropic-key', ['key' => 'sk-ant-x'])->assertUnauthorized();
    }

    public function test_store_verifies_key_and_never_returns_it_raw(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200)]);

        $user = User::factory()->create();
        $rawKey = 'sk-ant-live-key-abcdef123456';

        $response = $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/anthropic-key', ['key' => $rawKey]);

        $response->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('key.has_key', true);

        // The raw key must never appear in the response body.
        $this->assertStringNotContainsString($rawKey, $response->getContent());
        $this->assertNotNull($user->fresh()->anthropic_key_verified_at);
    }

    public function test_store_reports_unverified_when_key_is_rejected(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid']], 401)]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/anthropic-key', ['key' => 'sk-ant-bad-key-abcdef12'])
            ->assertOk()
            ->assertJsonPath('verified', false);

        $this->assertNull($user->fresh()->anthropic_key_verified_at);
    }

    public function test_store_validates_key_presence(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/anthropic-key', ['key' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('key');
    }

    public function test_destroy_removes_the_key(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200)]);

        $user = User::factory()->create();
        app(AnthropicKeyService::class)->store($user, 'sk-ant-remove-abcdef123456');

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->deleteJson('/api/anthropic-key')
            ->assertOk();

        $this->assertNull($user->fresh()->encrypted_anthropic_key);
    }

    public function test_user_cannot_see_another_users_key(): void
    {
        $owner = User::factory()->create();
        app(AnthropicKeyService::class)->store($owner, 'sk-ant-owner-secret-123456');

        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/anthropic-key')
            ->assertOk()
            ->assertJsonPath('key.has_key', false)
            ->assertJsonPath('key.masked', null);
    }
}
