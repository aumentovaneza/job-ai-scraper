<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    protected $model = FollowUp::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'application_id' => Application::factory(),
            'due_at' => now(),
            'kind' => 'nudge',
            'status' => 'drafted',
            'draft_content' => fake()->paragraph(),
        ];
    }
}
