<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DefaultStages;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the owner/admin account from env (T-09). On Laravel Cloud the deploy
     * runs `php artisan migrate --seed`; the owner then invites test users from
     * the admin panel. Idempotent — safe to run on every deploy.
     */
    public function run(): void
    {
        $email = env('OWNER_EMAIL');
        $password = env('OWNER_PASSWORD');

        if (blank($email) || blank($password)) {
            $this->command?->warn('OWNER_EMAIL / OWNER_PASSWORD not set — skipping owner seed.');

            return;
        }

        $owner = User::updateOrCreate(
            ['email' => strtolower($email)],
            [
                'name' => env('OWNER_NAME', 'Owner'),
                'password' => Hash::make($password),
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        // Seed the owner's default application pipeline (T-40). Idempotent, so
        // re-running the seeder on deploy leaves an existing pipeline untouched.
        DefaultStages::seedFor($owner);

        $this->command?->info("Owner account ensured for {$email}.");
    }
}
