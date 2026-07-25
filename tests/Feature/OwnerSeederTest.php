<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

afterEach(function () {
    putenv('OWNER_EMAIL');
    putenv('OWNER_PASSWORD');
    unset($_ENV['OWNER_EMAIL'], $_ENV['OWNER_PASSWORD'], $_SERVER['OWNER_EMAIL'], $_SERVER['OWNER_PASSWORD']);
});

function setOwnerEnv(string $email, string $password): void
{
    putenv("OWNER_EMAIL={$email}");
    putenv("OWNER_PASSWORD={$password}");
    $_ENV['OWNER_EMAIL'] = $_SERVER['OWNER_EMAIL'] = $email;
    $_ENV['OWNER_PASSWORD'] = $_SERVER['OWNER_PASSWORD'] = $password;
}

it('seeds an admin owner from env', function () {
    setOwnerEnv('owner@jobscope.dev', 'super-secret-pw');

    test()->seed(DatabaseSeeder::class);

    $owner = User::where('email', 'owner@jobscope.dev')->first();
    expect($owner)->not->toBeNull();
    expect((bool) $owner->is_admin)->toBeTrue();
});

it('is idempotent — re-seeding does not duplicate the owner', function () {
    setOwnerEnv('owner@jobscope.dev', 'super-secret-pw');

    test()->seed(DatabaseSeeder::class);
    test()->seed(DatabaseSeeder::class);

    expect(User::where('email', 'owner@jobscope.dev')->count())->toBe(1);
});

it('skips seeding when owner env is absent', function () {
    test()->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0);
});
