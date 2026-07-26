<?php

use App\Models\Snippet;
use App\Models\User;

use function Pest\Laravel\actingAs;

function snippetApi()
{
    return test()->withHeader('Referer', config('app.url'));
}

it('lists the user\'s snippets in display order', function () {
    $user = User::factory()->create();
    Snippet::create(['user_id' => $user->id, 'label' => 'B', 'content_md' => 'b', 'position' => 2]);
    Snippet::create(['user_id' => $user->id, 'label' => 'A', 'content_md' => 'a', 'position' => 1]);

    actingAs($user);
    $response = snippetApi()->getJson('/api/snippets')->assertOk();

    expect($response->json('data.0.label'))->toBe('A');
    expect($response->json('data.1.label'))->toBe('B');
});

it('creates a snippet for the current user', function () {
    $user = User::factory()->create();

    actingAs($user);
    snippetApi()->postJson('/api/snippets', ['label' => 'Closing', 'content_md' => 'Best regards,'])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Closing');

    $snippet = Snippet::withoutGlobalScopes()->first();
    expect($snippet->user_id)->toBe($user->id);
});

it('validates snippet input', function () {
    $user = User::factory()->create();

    actingAs($user);
    snippetApi()->postJson('/api/snippets', ['label' => ''])->assertStatus(422);
});

it('updates and deletes a snippet', function () {
    $user = User::factory()->create();
    $snippet = Snippet::create(['user_id' => $user->id, 'label' => 'X', 'content_md' => 'x']);

    actingAs($user);
    snippetApi()->patchJson("/api/snippets/{$snippet->id}", ['label' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.label', 'Renamed');

    snippetApi()->deleteJson("/api/snippets/{$snippet->id}")->assertOk();

    expect(Snippet::withoutGlobalScopes()->count())->toBe(0);
});

it('does not let user B read, update, or delete user A\'s snippet', function () {
    $userA = User::factory()->create();
    $snippet = Snippet::create(['user_id' => $userA->id, 'label' => 'A', 'content_md' => 'secret']);

    $userB = User::factory()->create();
    actingAs($userB);

    // Not visible in B's list.
    snippetApi()->getJson('/api/snippets')->assertOk()->assertJsonCount(0, 'data');
    // 404 from the scope on route binding.
    snippetApi()->patchJson("/api/snippets/{$snippet->id}", ['label' => 'hacked'])->assertNotFound();
    snippetApi()->deleteJson("/api/snippets/{$snippet->id}")->assertNotFound();

    expect($snippet->fresh()->label)->toBe('A');
});
