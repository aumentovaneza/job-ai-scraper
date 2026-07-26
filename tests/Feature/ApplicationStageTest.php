<?php

use App\Models\ApplicationStage;
use App\Models\Invitation;
use App\Models\User;
use App\Support\DefaultStages;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * A request carrying the stateful Referer so Sanctum treats it as a first-party
 * SPA call, mirroring how the React frontend hits the API.
 */
function stagesRequest()
{
    return test()->withHeader('Referer', config('app.url'));
}

// --- Seeding ----------------------------------------------------------------

it('seeds the default pipeline when a user accepts an invite', function () {
    $invitation = Invitation::factory()->create(['email' => 'new@example.test']);

    stagesRequest()->postJson("/api/invitations/{$invitation->token}/accept", [
        'name' => 'New User',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertCreated();

    $user = User::where('email', 'new@example.test')->firstOrFail();

    expect(ApplicationStage::withoutGlobalScopes()->where('user_id', $user->id)->count())
        ->toBe(count(DefaultStages::STAGES));

    // Terminal/success flags land on the right stages.
    $accepted = ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $user->id)->where('name', 'Accepted')->first();
    expect($accepted->is_terminal)->toBeTrue();
    expect($accepted->is_success)->toBeTrue();
});

it('does not re-seed stages for a user that already has them', function () {
    $user = User::factory()->create();
    DefaultStages::seedFor($user);
    DefaultStages::seedFor($user);

    expect(ApplicationStage::withoutGlobalScopes()->where('user_id', $user->id)->count())
        ->toBe(count(DefaultStages::STAGES));
});

// --- CRUD -------------------------------------------------------------------

it('requires authentication to list stages', function () {
    getJson('/api/application-stages')->assertUnauthorized();
});

it('lists only the current user\'s stages in position order', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    DefaultStages::seedFor($me);
    DefaultStages::seedFor($other);

    actingAs($me);
    $response = stagesRequest()->getJson('/api/application-stages')->assertOk();

    expect($response->json('data'))->toHaveCount(count(DefaultStages::STAGES));
    expect($response->json('data.0.name'))->toBe('Saved');
    expect($response->json('data.1.name'))->toBe('Applied');
});

it('creates a stage at the end of the pipeline by default', function () {
    $me = User::factory()->create();
    DefaultStages::seedFor($me);
    actingAs($me);

    $response = stagesRequest()->postJson('/api/application-stages', [
        'name' => 'Take-home',
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Take-home');
    // Highest default position is 9 (Withdrawn), so a new stage lands at 10.
    expect($response->json('data.position'))->toBe(10);
});

it('updates a stage it owns', function () {
    $me = User::factory()->create();
    actingAs($me);
    $stage = ApplicationStage::factory()->create(['user_id' => $me->id, 'name' => 'Old']);

    stagesRequest()->patchJson("/api/application-stages/{$stage->id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');

    expect($stage->fresh()->name)->toBe('New');
});

it('deletes a stage it owns', function () {
    $me = User::factory()->create();
    actingAs($me);
    $stage = ApplicationStage::factory()->create(['user_id' => $me->id]);

    stagesRequest()->deleteJson("/api/application-stages/{$stage->id}")->assertNoContent();

    $this->assertDatabaseMissing('application_stages', ['id' => $stage->id]);
});

it('reorders stages', function () {
    $me = User::factory()->create();
    DefaultStages::seedFor($me);
    actingAs($me);

    $ids = ApplicationStage::withoutGlobalScopes()
        ->where('user_id', $me->id)->orderBy('position')->pluck('id')->all();

    // Move the last stage to the front.
    $reordered = array_merge([array_pop($ids)], $ids);

    $response = stagesRequest()->putJson('/api/application-stages/reorder', [
        'stage_ids' => $reordered,
    ])->assertOk();

    expect($response->json('data.0.id'))->toBe($reordered[0]);
    expect(ApplicationStage::withoutGlobalScopes()->find($reordered[0])->position)->toBe(0);
});

// --- Multi-tenant isolation (mandatory per PLAN.md §7) ----------------------

it('cannot update or delete another user\'s stage (404 from scope)', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $foreign = ApplicationStage::factory()->create(['user_id' => $other->id]);

    actingAs($me);

    stagesRequest()->patchJson("/api/application-stages/{$foreign->id}", ['name' => 'Hacked'])
        ->assertNotFound();
    stagesRequest()->deleteJson("/api/application-stages/{$foreign->id}")
        ->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('Hacked');
});

it('cannot reorder using another user\'s stage id', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $mine = ApplicationStage::factory()->create(['user_id' => $me->id]);
    $foreign = ApplicationStage::factory()->create(['user_id' => $other->id]);

    actingAs($me);

    stagesRequest()->putJson('/api/application-stages/reorder', [
        'stage_ids' => [$mine->id, $foreign->id],
    ])->assertStatus(422)->assertJsonValidationErrors('stage_ids.1');
});
