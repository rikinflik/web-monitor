<?php

use App\Models\Monitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Insert one paired seo_checks row for every monitor lacking one.
     *
     * The pairing invariant is enforced from the Monitor::created hook onward,
     * so monitors that already existed when this feature shipped need a paired
     * row created here.
     */
    public function up(): void
    {
        Monitor::whereDoesntHave('seoCheck')->chunkById(500, function ($monitors) {
            $now = now();
            $rows = $monitors->map(fn (Monitor $monitor) => [
                'monitor_id' => $monitor->id,
                'www_redirect' => 'none',
                'https_redirect' => 'none',
                'trailing_slash_redirect' => 'none',
                'robots_ok' => false,
                'sitemap_ok' => false,
                'interval' => 1440,
                'last_checked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows !== []) {
                DB::table('seo_checks')->insert($rows);
            }
        });
    }

    /**
     * No-op: create_seo_checks_table::down() drops the whole table, which
     * removes the backfilled rows along with every other seo_checks row.
     */
    public function down(): void
    {
        // Intentionally empty — see method docblock.
    }
};
