<?php

use App\Models\Invitation;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

function stateful()
{
    return test()->withHeader('Referer', config('app.url'));
}

// --- Admin: issuing invites -------------------------------------------------

it('lets an admin issue an invitation', function () {
    actingAs(User::factory()->admin()->create());

    $response = stateful()->postJson('/api/invitations', ['email' => 'invitee@test.dev']);

    $response->assertCreated()->assertJsonPath('invitation.email', 'invitee@test.dev');
    expect(Invitation::where('email', 'invitee@test.dev')->exists())->toBeTrue();
});

it('forbids a non-admin from issuing invitations', function () {
    actingAs(User::factory()->create()); // is_admin defaults falsy

    stateful()->postJson('/api/invitations', ['email' => 'x@test.dev'])
        ->assertForbidden();

    expect(Invitation::count())->toBe(0);
});

it('requires authentication to issue invitations', function () {
    postJson('/api/invitations', ['email' => 'x@test.dev'])->assertUnauthorized();
});

it('rejects inviting an email that already has an account', function () {
    actingAs(User::factory()->admin()->create());
    User::factory()->create(['email' => 'taken@test.dev']);

    stateful()->postJson('/api/invitations', ['email' => 'taken@test.dev'])
        ->assertStatus(422);
});

it('replaces an earlier pending invite for the same email', function () {
    actingAs(User::factory()->admin()->create());

    stateful()->postJson('/api/invitations', ['email' => 'dup@test.dev'])->assertCreated();
    stateful()->postJson('/api/invitations', ['email' => 'dup@test.dev'])->assertCreated();

    expect(Invitation::where('email', 'dup@test.dev')->count())->toBe(1);
});

// --- Public: accepting an invite -------------------------------------------

it('resolves a valid token to the invited email', function () {
    $invite = Invitation::factory()->create(['email' => 'new@test.dev']);

    stateful()->getJson("/api/invitations/{$invite->token}")
        ->assertOk()
        ->assertJsonPath('email', 'new@test.dev');
});

it('creates the account and logs in when accepting an invite', function () {
    $invite = Invitation::factory()->create(['email' => 'new@test.dev']);

    $response = stateful()->postJson("/api/invitations/{$invite->token}/accept", [
        'name' => 'New User',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertCreated()->assertJsonPath('user.email', 'new@test.dev');
    expect($invite->fresh()->accepted_at)->not->toBeNull();

    $user = User::where('email', 'new@test.dev')->first();
    expect($user)->not->toBeNull();
    expect($user->is_admin)->toBeFalsy();
    test()->assertAuthenticatedAs($user);
});

it('rejects accepting an expired invitation', function () {
    $invite = Invitation::factory()->expired()->create();

    stateful()->postJson("/api/invitations/{$invite->token}/accept", [
        'name' => 'Late User',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(410);

    expect(User::where('email', $invite->email)->exists())->toBeFalse();
});

it('rejects accepting an already-used invitation', function () {
    $invite = Invitation::factory()->accepted()->create();

    stateful()->getJson("/api/invitations/{$invite->token}")->assertStatus(410);
});

it('validates password confirmation on accept', function () {
    $invite = Invitation::factory()->create();

    stateful()->postJson("/api/invitations/{$invite->token}/accept", [
        'name' => 'New User',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'mismatch',
    ])->assertStatus(422);
});
