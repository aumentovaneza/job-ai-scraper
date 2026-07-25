<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AnthropicKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnthropicKeyService $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = app(AnthropicKeyService::class);
    }

    public function test_stores_key_encrypted_and_retrieves_it(): void
    {
        $user = User::factory()->create();

        $this->keys->store($user, 'sk-ant-test-abcdef123456');

        // Persisted value is ciphertext, not the raw key.
        $this->assertNotSame('sk-ant-test-abcdef123456', $user->encrypted_anthropic_key);
        $this->assertSame('sk-ant-test-abcdef123456', Crypt::decryptString($user->encrypted_anthropic_key));
        $this->assertSame('sk-ant-test-abcdef123456', $this->keys->retrieve($user));
    }

    public function test_masks_key_without_revealing_the_middle(): void
    {
        $user = User::factory()->create();
        $this->keys->store($user, 'sk-ant-secretmiddle-9999');

        $masked = $this->keys->masked($user);

        $this->assertNotNull($masked);
        $this->assertStringNotContainsString('secretmiddle', $masked);
        $this->assertStringStartsWith('sk-ant-s', $masked);
        $this->assertStringEndsWith('9999', $masked);
    }

    public function test_verify_marks_key_valid_on_successful_ping(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200)]);

        $user = User::factory()->create();
        $this->keys->store($user, 'sk-ant-valid-key-123456');

        $this->assertTrue($this->keys->verify($user));
        $this->assertNotNull($user->fresh()->anthropic_key_verified_at);
    }

    public function test_verify_fails_and_clears_stamp_on_rejected_key(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $user = User::factory()->create();
        $this->keys->store($user, 'sk-ant-bad-key-1234567');

        $this->assertFalse($this->keys->verify($user));
        $this->assertNull($user->fresh()->anthropic_key_verified_at);
    }

    public function test_forget_removes_key_and_stamp(): void
    {
        $user = User::factory()->create(['anthropic_key_verified_at' => now()]);
        $this->keys->store($user, 'sk-ant-remove-me-123456');

        $this->keys->forget($user);

        $fresh = $user->fresh();
        $this->assertNull($fresh->encrypted_anthropic_key);
        $this->assertNull($fresh->anthropic_key_verified_at);
        $this->assertFalse($this->keys->hasKey($fresh));
    }
}
