<?php

namespace App\Jobs;

use App\Models\AlertSource;
use App\Services\AlertCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the check for a single AlertSource.
 *
 * Dispatched queued hourly from the scheduler (T4) and synchronously
 * (dispatchSync) from the manual "check now" controller action (T5), so a
 * manual re-check reflects fresh results immediately regardless of the
 * queue connection.
 *
 * tries/backoff exist for genuinely transient infrastructure failures (DB
 * unreachable, worker crash) — NOT for a broken feed: AlertCheckService::check()
 * catches AlertFeedException itself and never rethrows it, so this job
 * completes successfully even when the upstream feed is down or rate-limited.
 * See AlertCheckService's class docblock for why retrying a feed failure at
 * this layer would be actively harmful for the three GitHub-Advisories-backed
 * sources sharing one rate-limit budget.
 */
class CheckAlertSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(public AlertSource $alertSource)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(AlertCheckService $service): void
    {
        $service->check($this->alertSource);
    }
}
