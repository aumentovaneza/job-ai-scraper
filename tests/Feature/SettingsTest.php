<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_persists_timezone_and_caps(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->putJson('/api/settings', [
                'timezone' => 'Asia/Singapore',
                'daily_ai_spend_cap_cents' => 500,
                'weekly_ai_spend_cap_cents' => 2500,
            ])
            ->assertOk()
            ->assertJsonPath('settings.timezone', 'Asia/Singapore')
            ->assertJsonPath('settings.daily_ai_spend_cap_cents', 500);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'timezone' => 'Asia/Singapore',
            'daily_ai_spend_cap_cents' => 500,
            'weekly_ai_spend_cap_cents' => 2500,
        ]);
    }

    public function test_update_rejects_invalid_timezone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->putJson('/api/settings', ['timezone' => 'Mars/Phobos'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('timezone');
    }

    public function test_can_clear_a_cap_with_null(): void
    {
        $user = User::factory()->create(['daily_ai_spend_cap_cents' => 500]);

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->putJson('/api/settings', ['daily_ai_spend_cap_cents' => null])
            ->assertOk()
            ->assertJsonPath('settings.daily_ai_spend_cap_cents', null);
    }

    public function test_usage_reflects_ledger_and_caps(): void
    {
        $user = User::factory()->create([
            'daily_ai_spend_cap_cents' => 1000,
            'weekly_ai_spend_cap_cents' => 5000,
        ]);
        AiCall::create([
            'user_id' => $user->id, 'provider' => 'anthropic', 'model' => 'claude-opus-4-8',
            'endpoint' => 'messages', 'cost_cents' => 320, 'purpose' => 'voice_profile', 'status' => 'ok',
        ]);

        $this->actingAs($user)
            ->getJson('/api/ai/usage')
            ->assertOk()
            ->assertJsonPath('day.spent_cents', 320)
            ->assertJsonPath('day.cap_cents', 1000)
            ->assertJsonPath('week.spent_cents', 320);
    }

    public function test_usage_is_isolated_per_user(): void
    {
        $spender = User::factory()->create();
        AiCall::create([
            'user_id' => $spender->id, 'provider' => 'anthropic', 'model' => 'claude-opus-4-8',
            'endpoint' => 'messages', 'cost_cents' => 900, 'purpose' => 'voice_profile', 'status' => 'ok',
        ]);

        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/ai/usage')
            ->assertOk()
            ->assertJsonPath('day.spent_cents', 0);
    }
}
