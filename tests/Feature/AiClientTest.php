<?php

namespace Tests\Feature;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AiException;
use App\Models\AiCall;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiProvider;
use App\Services\Ai\SpendTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep retry backoff instant in tests.
        config(['services.anthropic.retry_base_ms' => 0, 'services.openai.retry_base_ms' => 0]);
    }

    private function anthropicClientFor(User $user)
    {
        app(AiKeyService::class)->store($user, AiProvider::Anthropic, 'sk-ant-key-abcdef123456');

        return app(AiClientFactory::class)->for($user->fresh(), AiProvider::Anthropic);
    }

    private function openAiClientFor(User $user)
    {
        app(AiKeyService::class)->store($user, AiProvider::OpenAi, 'sk-openai-key-abcdef123456');

        return app(AiClientFactory::class)->for($user->fresh(), AiProvider::OpenAi);
    }

    public function test_anthropic_records_ai_call_and_returns_text_on_success(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-4-8',
            'content' => [['type' => 'text', 'text' => 'hello world']],
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 400_000],
        ], 200)]);

        $user = User::factory()->create();

        $response = $this->anthropicClientFor($user)->messages(
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

    public function test_openai_records_ai_call_and_returns_text_on_success(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hello world']]],
            'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 400_000],
        ], 200)]);

        $user = User::factory()->create();

        $response = $this->openAiClientFor($user)->messages(
            ['max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'hi']]],
            purpose: 'voice_profile',
        );

        $this->assertSame('hello world', $response->text);
        // 1M input @ $2.50 + 0.4M output @ $10 = 250 + 400 = 650 cents.
        $this->assertSame(650, $response->costCents);

        $this->assertDatabaseHas('ai_calls', [
            'user_id' => $user->id,
            'provider' => 'openai',
            'endpoint' => 'chat.completions',
            'purpose' => 'voice_profile',
            'status' => 'ok',
            'cost_cents' => 650,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'openai.com/v1/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer sk-openai-key-abcdef123456'));
    }

    public function test_records_error_and_throws_invalid_key_on_401(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $user = User::factory()->create();

        try {
            $this->anthropicClientFor($user)->messages(
                ['messages' => [['role' => 'user', 'content' => 'hi']]],
                purpose: 'voice_profile',
            );
            $this->fail('Expected AiException.');
        } catch (AiException $e) {
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

        $response = $this->anthropicClientFor($user)->messages(
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
            $this->anthropicClientFor($user->fresh())->messages(
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

    public function test_factory_uses_the_users_active_provider(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'choices' => [['message' => ['content' => 'from openai']]],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            ], 200),
        ]);

        $user = User::factory()->create();
        $keys = app(AiKeyService::class);
        $keys->store($user, AiProvider::OpenAi, 'sk-openai-key-abcdef123456');
        $keys->setActiveProvider($user, AiProvider::OpenAi);

        $response = app(AiClientFactory::class)->forUser($user->fresh())->messages(
            ['messages' => [['role' => 'user', 'content' => 'hi']]],
            purpose: 'voice_profile',
        );

        $this->assertSame('from openai', $response->text);
    }
}
