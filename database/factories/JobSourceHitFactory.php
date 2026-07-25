<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobSourceHit>
 */
class JobSourceHitFactory extends Factory
{
    protected $model = JobSourceHit::class;

    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'job_source_id' => JobSource::factory(),
            'source_url' => fake()->url(),
            'first_seen_at' => now(),
        ];
    }
}
