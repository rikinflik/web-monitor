<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->timestamp('down_since')->nullable()->after('status');
            $table->timestamp('last_down_notified_at')->nullable()->after('down_since');
            $table->unsignedInteger('down_reminders_sent')->default(0)->after('last_down_notified_at');
        });

        $this->backfillOngoingOutages();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['down_since', 'last_down_notified_at', 'down_reminders_sent']);
        });
    }

    /**
     * Seed the new columns for monitors that are already down.
     *
     * Without this, the first check after deploying would treat a long-running
     * outage as brand new and restart the backoff at 15 minutes. Instead each
     * ongoing outage starts at the slowest step, so an already-known outage
     * resumes quietly rather than bursting.
     */
    protected function backfillOngoingOutages(): void
    {
        $slowestStep = max(count(config('monitoring.down_reminder_backoff_minutes', [15])) - 1, 0);

        DB::table('monitors')->where('status', 'down')->orderBy('id')
            ->each(function (object $monitor) use ($slowestStep) {
                DB::table('monitors')->where('id', $monitor->id)->update([
                    'down_since' => $this->outageStartedAt($monitor->id),
                    'last_down_notified_at' => now(),
                    'down_reminders_sent' => $slowestStep,
                ]);
            });
    }

    /**
     * Best guess at when the current outage began, read from the log history.
     *
     * The outage starts at the first "down" log recorded after the most recent
     * "up" log; monitors with no usable history fall back to now.
     */
    protected function outageStartedAt(int $monitorId): string
    {
        $lastUpAt = DB::table('monitor_logs')
            ->where('monitor_id', $monitorId)
            ->where('status', 'up')
            ->max('checked_at');

        $firstDownAt = DB::table('monitor_logs')
            ->where('monitor_id', $monitorId)
            ->where('status', 'down')
            ->when($lastUpAt, fn ($query) => $query->where('checked_at', '>', $lastUpAt))
            ->min('checked_at');

        return $firstDownAt ?? now()->toDateTimeString();
    }
};
