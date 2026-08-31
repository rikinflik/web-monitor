<?php

namespace Tests\Feature;

use App\Jobs\CheckSeoJob;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The SEO schedule is driven by fixed wall-clock times (06:00, 10:00 and 14:00
 * Europe/Madrid), not by each entry's own interval — see routes/console.php.
 */
#[Group('seo-scheduler')]
class SeoSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_entry_is_dispatched_at_a_scheduled_hour(): void
    {
        Queue::fake();

        // Never checked.
        Monitor::factory()->create();
        // Checked moments ago: its 1440-minute interval has not elapsed, and
        // that must no longer matter.
        Monitor::factory()->create()->seoCheck->update(['last_checked_at' => now()]);

        $this->travelTo(Carbon::parse('2026-01-15 06:00:00', 'Europe/Madrid'));
        $this->artisan('schedule:run');

        Queue::assertPushed(CheckSeoJob::class, 2);
    }

    public function test_nothing_is_dispatched_outside_the_scheduled_hours(): void
    {
        Queue::fake();

        Monitor::factory()->create();

        $this->travelTo(Carbon::parse('2026-01-15 07:00:00', 'Europe/Madrid'));
        $this->artisan('schedule:run');

        Queue::assertNotPushed(CheckSeoJob::class);
    }

    public function test_all_three_daily_slots_dispatch(): void
    {
        Monitor::factory()->count(2)->create();

        foreach (['06:00', '10:00', '14:00'] as $slot) {
            Queue::fake();

            $this->travelTo(Carbon::parse("2026-01-15 {$slot}:00", 'Europe/Madrid'));
            $this->artisan('schedule:run');

            Queue::assertPushed(CheckSeoJob::class, 2);
        }
    }
}
