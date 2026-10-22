<?php

namespace Tests\Feature;

use App\Jobs\CheckAlertSourceJob;
use App\Models\AlertSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The `alerts:check` command dispatches one CheckAlertSourceJob per
 * AlertSource row, regardless of how many rows exist or where they came
 * from.
 *
 * These tests rely on the six real AlertSource rows installed by the
 * `create_alert_sources_table` migration via AlertSourceSeeder (not
 * factories): RefreshDatabase re-runs that migration before every test, so
 * the six rows are always present, and asserting "exactly 6" here doubles
 * as a guard that the real seeded set has not drifted.
 */
#[Group('alerts-check-command')]
class AlertsCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_one_job_per_alert_source(): void
    {
        Queue::fake();

        $this->assertSame(6, AlertSource::count());

        $this->artisan('alerts:check')->assertExitCode(0);

        Queue::assertPushed(CheckAlertSourceJob::class, 6);
    }

    public function test_it_dispatches_a_job_for_each_individual_source(): void
    {
        Queue::fake();

        $this->artisan('alerts:check')->assertExitCode(0);

        AlertSource::all()->each(function (AlertSource $source) {
            Queue::assertPushed(
                CheckAlertSourceJob::class,
                fn (CheckAlertSourceJob $job) => $job->alertSource->is($source)
            );
        });
    }
}
