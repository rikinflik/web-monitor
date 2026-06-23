<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'url' => 'https://example.com',
            'interval' => 60,
            'timeout' => 10,
            'expected_status_code' => 200,
            'keyword' => null,
            'status' => 'up',
            'last_checked_at' => null,
            'user_id' => User::factory(),
            'webhook_url' => null,
            'basic_auth_user' => null,
            'basic_auth_password' => null,
        ];
    }

    public function down(): static
    {
        return $this->state(['status' => 'down']);
    }

    public function withKeyword(string $keyword): static
    {
        return $this->state(['keyword' => $keyword]);
    }

    public function withWebhook(string $url): static
    {
        return $this->state(['webhook_url' => $url]);
    }

    public function withBasicAuth(string $user, string $password): static
    {
        return $this->state([
            'basic_auth_user' => $user,
            'basic_auth_password' => $password,
        ]);
    }
}
