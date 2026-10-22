<?php

namespace App\Console\Commands;

use App\Jobs\CheckAlertSourceJob;
use App\Models\AlertSource;
use Illuminate\Console\Command;

/**
 * Dispatch a CheckAlertSourceJob for every tracked AlertSource.
 *
 * Registered hourly in routes/console.php; also runnable by hand
 * (`ddev artisan alerts:check`) to force an immediate poll of all six
 * ecosystems without waiting for the next scheduled run.
 */
final class CheckAlertSources extends Command
{
    protected $signature = 'alerts:check';

    protected $description = 'Dispatch a check job for every tracked AlertSource (PHP, Drupal, WordPress, Laravel, Vue, Angular) — run by hand to force an immediate poll.';

    public function handle(): int
    {
        $dispatched = 0;

        AlertSource::cursor()->each(function (AlertSource $source) use (&$dispatched) {
            CheckAlertSourceJob::dispatch($source);
            $dispatched++;
        });

        $this->info("Dispatched {$dispatched} alert check job(s).");

        return self::SUCCESS;
    }
}
