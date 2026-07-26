<?php

use App\Jobs\GenerateWeeklyDigestJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('dispatches a digest job for every user', function () {
    Queue::fake();
    User::factory()->count(3)->create();

    $this->artisan('insights:digest')->assertSuccessful();

    Queue::assertPushed(GenerateWeeklyDigestJob::class, 3);
});

it('can target a single user', function () {
    Queue::fake();
    $users = User::factory()->count(3)->create();

    $this->artisan('insights:digest', ['--user' => $users->first()->id])->assertSuccessful();

    Queue::assertPushed(GenerateWeeklyDigestJob::class, 1);
});
