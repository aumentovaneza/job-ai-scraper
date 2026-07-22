<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@test.dev',
            'password' => Hash::make('correct-horse'),
        ]);

        // A first-party Referer makes the request "stateful" (session-backed),
        // exactly as the browser SPA sends it.
        $response = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => 'owner@test.dev',
                'password' => 'correct-horse',
            ]);

        $response->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'owner@test.dev',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'owner@test.dev',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');

        $this->assertGuest();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_themselves(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.encrypted_anthropic_key');
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/logout')
            ->assertOk();
    }

    /**
     * Mandatory isolation check (PLAN.md §7): the /me endpoint never leaks
     * another user's identity.
     */
    public function test_me_returns_only_the_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userB)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $userB->id)
            ->assertJsonMissing(['id' => $userA->id]);
    }
}
