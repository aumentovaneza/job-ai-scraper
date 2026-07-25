<?php

namespace Tests\Feature;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AnthropicException;
use App\Models\AiCall;
use App\Models\User;
use App\Services\Ai\AnthropicClientFactory;
use App\Services\Ai\AnthropicKeyService;
use App\Services\Ai\SpendTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep retry backoff instant in tests.
        config(['services.anthropic.retry_base_ms' => 0]);
    }

    private function clientFor(User $user)
    {
        app(AnthropicKeyService::class)->store($user, 'sk-ant-key-abcdef123456');

        return app(AnthropicClientFactory::class)->forUser($user->fresh());
    }

    public function test_records_ai_call_and_returns_text_on_success(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-4-8',
            'content' => [['type' => 'text', 'text' => 'hello world']],
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 400_000],
        ], 200)]);

        $user = User::factory()->create();

        $response = $this->clientFor($user)->messages(
            ['max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'hi']]],
            purpose: 'voice_profile',
        );

        $this->assertSame('hello world', $response->text);
        // 1M input @ $5 + 0.4M output @ $25 = 500 + 1000 = 1500 cents.
        $this->assertSame(1500, $response->costCents);

        $this->assertDatabaseHas('ai_calls', [
            'user_id' => $user->id,
            'provider' => 'anthropic',
            'purpose' => 'voice_profile',
            'status' => 'ok',
            'cost_cents' => 1500,
        ]);
    }

    public function test_records_error_and_throws_invalid_key_on_401(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $user = User::factory()->create();

        try {
            $this->clientFor($user)->messages(
                ['messages' => [['role' => 'user', 'content' => 'hi']]],
                purpose: 'voice_profile',
            );
            $this->fail('Expected AnthropicException.');
        } catch (AnthropicException $e) {
            $this->assertTrue($e->invalidKey);
        }

        $this->assertDatabaseHas('ai_calls', [
            'user_id' => $user->id,
            'status' => 'error',
        ]);
    }

    public function test_retries_transient_failures_then_succeeds(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'overloaded']], 529)
            ->push(['content' => [['type' => 'text', 'text' => 'recovered']], 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->clientFor($user)->messages(
            ['messages' => [['role' => 'user', 'content' => 'hi']]],
            purpose: 'voice_profile',
        );

        $this->assertSame('recovered', $response->text);
        // Only the successful attempt is recorded on the ledger.
        $this->assertSame(1, AiCall::where('user_id', $user->id)->where('status', 'ok')->count());
    }

    public function test_spend_cap_blocks_before_any_request_is_sent(): void
    {
        Http::fake();

        $user = User::factory()->create(['daily_ai_spend_cap_cents' => 100]);
        // Already spent the whole daily cap today.
        AiCall::create([
            'user_id' => $user->id, 'provider' => 'anthropic', 'model' => 'claude-opus-4-8',
            'endpoint' => 'messages', 'cost_cents' => 100, 'purpose' => 'voice_profile', 'status' => 'ok',
        ]);

        $this->expectException(AiBudgetExceededException::class);

        try {
            $this->clientFor($user->fresh())->messages(
                ['messages' => [['role' => 'user', 'content' => 'hi']]],
                purpose: 'voice_profile',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_spend_tracker_is_isolated_per_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        AiCall::create([
            'user_id' => $userA->id, 'provider' => 'anthropic', 'model' => 'claude-opus-4-8',
            'endpoint' => 'messages', 'cost_cents' => 250, 'purpose' => 'voice_profile', 'status' => 'ok',
        ]);

        $spend = app(SpendTracker::class);
        $this->assertSame(250, $spend->spentTodayCents($userA));
        $this->assertSame(0, $spend->spentTodayCents($userB));
    }
}
