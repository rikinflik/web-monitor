<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Models\Monitor;
use App\Services\MonitoringService;

class CheckMonitorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(public Monitor $monitor)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(MonitoringService $service): void
    {
        $service->check($this->monitor);
    }
}
