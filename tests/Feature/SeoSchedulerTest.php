<?php

namespace Tests\Feature;

use App\Jobs\CheckSeoJob;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * @group seo-scheduler
 */
class SeoSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_entry_is_dispatched_and_not_due_entry_is_skipped(): void
    {
        Queue::fake();

        // Due: last_checked_at is null (never checked).
        $dueSeoCheck = Monitor::factory()->create()->seoCheck;

        // Not due: checked just now, default 1440-minute interval not elapsed.
        $notDue = Monitor::factory()->create()->seoCheck;
        $notDue->update(['last_checked_at' => now()]);

        $this->artisan('schedule:run');

        Queue::assertPushed(
            CheckSeoJob::class,
            fn (CheckSeoJob $job) => $job->seoCheck->is($dueSeoCheck),
        );
        Queue::assertPushed(CheckSeoJob::class, 1);
    }
}
