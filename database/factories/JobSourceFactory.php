<?php

namespace Database\Factories;

use App\Models\JobSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobSource>
 */
class JobSourceFactory extends Factory
{
    protected $model = JobSource::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['ats_feed', 'career_page', 'rss']),
            'url' => fake()->url(),
            'config' => [],
            'cron_schedule' => '0 * * * *',
            'active' => true,
        ];
    }
}
