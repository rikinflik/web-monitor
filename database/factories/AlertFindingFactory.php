<?php

namespace Database\Factories;

use App\Models\AlertSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AlertFinding>
 */
class AlertFindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_source_id' => AlertSource::factory(),
            'external_id' => fake()->unique()->uuid(),
            'title' => fake()->sentence(),
            'url' => fake()->url(),
            'severity' => null,
            'published_at' => now(),
            'first_seen_at' => now(),
        ];
    }

    public function withSeverity(string $severity): static
    {
        return $this->state(['severity' => $severity]);
    }
}
