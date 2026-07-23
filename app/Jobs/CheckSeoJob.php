<?php

namespace App\Jobs;

use App\Models\SeoCheck;
use App\Services\SeoCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the SEO checks for a single entry.
 *
 * Dispatched queued from the periodic scheduler (routes/console.php) and
 * synchronously (dispatchSync) from the manual re-check controller action, so
 * fresh results are shown immediately regardless of the queue connection.
 */
class CheckSeoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(public SeoCheck $seoCheck)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(SeoCheckService $service): void
    {
        $service->check($this->seoCheck);
    }
}
