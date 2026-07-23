<?php

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\SeoCheck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeoCheck>
 *
 * Note (design §4.12): because Monitor::created auto-inserts a paired seo_checks
 * row and monitor_id is unique, prefer updating the auto-created relation over
 * calling SeoCheck::factory()->create() against a fresh Monitor::factory().
 * The definition/states remain useful for ->make()/->raw() and HasFactory.
 */
class SeoCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'www_redirect' => SeoCheck::NONE,
            'https_redirect' => SeoCheck::NONE,
            'trailing_slash_redirect' => SeoCheck::NONE,
            'robots_ok' => false,
            'sitemap_ok' => false,
            'interval' => 1440,
            'last_checked_at' => null,
        ];
    }

    public function withWwwRedirect(string $direction = SeoCheck::WWW_TO_NO_WWW): static
    {
        return $this->state(['www_redirect' => $direction]);
    }

    public function withHttpsRedirect(string $direction = SeoCheck::HTTP_TO_HTTPS): static
    {
        return $this->state(['https_redirect' => $direction]);
    }

    public function withTrailingSlash(string $direction = SeoCheck::WITH_SLASH): static
    {
        return $this->state(['trailing_slash_redirect' => $direction]);
    }

    public function robotsMissing(): static
    {
        return $this->state(['robots_ok' => false]);
    }

    public function robotsOk(): static
    {
        return $this->state(['robots_ok' => true]);
    }

    public function sitemapMissing(): static
    {
        return $this->state(['sitemap_ok' => false]);
    }

    public function sitemapOk(): static
    {
        return $this->state(['sitemap_ok' => true]);
    }

    public function checkedAt(?Carbon $when): static
    {
        return $this->state(['last_checked_at' => $when]);
    }
}
