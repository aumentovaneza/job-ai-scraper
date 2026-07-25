<?php

namespace Database\Factories;

use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    protected $model = JobPosting::class;

    public function definition(): array
    {
        $title = fake()->jobTitle();
        $company = fake()->company();
        $location = fake()->city();
        $min = fake()->numberBetween(60, 150) * 1000;

        return [
            'source_hash' => hash('sha256', Str::random(40)),
            'title' => $title,
            'company' => $company,
            'location' => $location,
            'remote_type' => fake()->randomElement(['remote', 'hybrid', 'onsite']),
            'salary_min' => $min,
            'salary_max' => $min + fake()->numberBetween(10, 60) * 1000,
            'salary_currency' => 'USD',
            'jd_text' => fake()->paragraphs(3, true),
            'apply_url' => fake()->url(),
            'posted_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
