<?php

namespace Database\Factories;

use App\Models\ApplicationStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationStage>
 */
class ApplicationStageFactory extends Factory
{
    protected $model = ApplicationStage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->word(),
            'position' => fake()->numberBetween(0, 20),
            'is_terminal' => false,
            'is_success' => false,
        ];
    }

    public function terminal(bool $success = false): static
    {
        return $this->state(fn () => ['is_terminal' => true, 'is_success' => $success]);
    }
}
