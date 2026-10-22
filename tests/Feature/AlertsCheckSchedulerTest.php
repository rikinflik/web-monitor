<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The alert-check schedule is registered as
 * `Schedule::command('alerts:check')->hourly()` in routes/console.php,
 * decoupled from the Monitor per-minute loop and the SEO thrice-daily block.
 *
 * Unlike SeoSchedulerTest's Schedule::call() closures — which run in-process
 * and are therefore directly observable with Queue::fake() — an
 * Artisan-command schedule entry is executed by forking a real OS
 * subprocess (Illuminate\Console\Scheduling\Event::execute() shells out via
 * Symfony Process). That subprocess boots its own fresh application against
 * its own fresh SQLite ":memory:" connection, which has no migrated schema,
 * so `artisan('schedule:run')` + Queue::fake() cannot observe the dispatch
 * at all here (verified directly: it reports the job pushed 0 times whether
 * the registration is correct or deleted entirely, i.e. it is not a
 * discriminating assertion for this kind of schedule entry).
 *
 * Instead, this test resolves the real Schedule registered by
 * routes/console.php (via `Artisan::call('schedule:list')`, which forces the
 * console kernel to load it without executing any event) and asks the
 * actual registered Event whether it isDue() at a given moment — exactly
 * the question `schedule:run` itself asks before forking. This still fails
 * the moment someone edits routes/console.php to the wrong cadence or
 * removes the registration (see sabotage evidence in the task report).
 */
#[Group('alerts-check-scheduler')]
class AlertsCheckSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function alertsCheckEvent(): Event
    {
        Artisan::call('schedule:list');

        $schedule = $this->app->make(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'alerts:check'));

        $this->assertNotNull($event, 'alerts:check is not registered on the schedule.');

        return $event;
    }

    public function test_it_is_due_on_the_hour(): void
    {
        $event = $this->alertsCheckEvent();

        $this->travelTo(Carbon::parse('2026-01-15 09:00:00'));

        $this->assertTrue($event->isDue($this->app));
    }

    public function test_it_is_not_due_off_the_hour(): void
    {
        $event = $this->alertsCheckEvent();

        $this->travelTo(Carbon::parse('2026-01-15 09:30:00'));

        $this->assertFalse($event->isDue($this->app));
    }

    public function test_it_is_due_again_on_the_next_hour(): void
    {
        $event = $this->alertsCheckEvent();

        $this->travelTo(Carbon::parse('2026-01-15 10:00:00'));

        $this->assertTrue($event->isDue($this->app));
    }
}
