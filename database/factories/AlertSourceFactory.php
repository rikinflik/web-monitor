<?php

namespace Database\Factories;

use App\Models\AlertSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AlertSource>
 */
class AlertSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, false),
            'name' => fake()->words(2, true),
            'kind' => AlertSource::KIND_RSS,
            'config' => ['url' => fake()->url()],
            'last_checked_at' => null,
        ];
    }

    public function githubAdvisory(): static
    {
        return $this->state([
            'kind' => AlertSource::KIND_GITHUB_ADVISORY,
            'config' => ['ecosystem' => 'composer', 'affects' => 'vendor/package'],
        ]);
    }

    public function checkedAt(?Carbon $when): static
    {
        return $this->state(['last_checked_at' => $when]);
    }
}
