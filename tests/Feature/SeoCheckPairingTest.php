<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\SeoCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests the one-to-one pairing invariant between Monitor and SeoCheck.
 *
 * @group seo-check-pairing
 */
class SeoCheckPairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_monitor_auto_creates_exactly_one_paired_seo_check(): void
    {
        $monitor = Monitor::factory()->create();

        $this->assertDatabaseCount('seo_checks', 1);
        $this->assertDatabaseHas('seo_checks', ['monitor_id' => $monitor->id]);
    }

    public function test_auto_created_seo_check_uses_default_values(): void
    {
        $monitor = Monitor::factory()->create();

        $this->assertDatabaseHas('seo_checks', [
            'monitor_id' => $monitor->id,
            'interval' => 1440,
            'www_redirect' => SeoCheck::NONE,
            'https_redirect' => SeoCheck::NONE,
            'trailing_slash_redirect' => SeoCheck::NONE,
            'robots_ok' => false,
            'sitemap_ok' => false,
            'last_checked_at' => null,
        ]);
    }

    public function test_deleting_a_monitor_cascade_removes_its_seo_check_and_logs(): void
    {
        $monitor = Monitor::factory()->create();
        $seoCheck = $monitor->seoCheck;
        $seoCheck->logs()->create([
            'www_redirect' => SeoCheck::NONE,
            'https_redirect' => SeoCheck::NONE,
            'trailing_slash_redirect' => SeoCheck::NONE,
            'robots_ok' => false,
            'sitemap_ok' => false,
            'checked_at' => now(),
        ]);

        $monitor->delete();

        $this->assertDatabaseMissing('seo_checks', ['id' => $seoCheck->id]);
        $this->assertDatabaseMissing('seo_check_logs', ['seo_check_id' => $seoCheck->id]);
    }

    public function test_backfill_migration_creates_paired_row_for_pre_existing_monitor(): void
    {
        // Insert a monitors row directly, bypassing the model's created hook,
        // so it has no paired seo_checks row (simulates a pre-feature monitor).
        $monitorId = DB::table('monitors')->insertGetId([
            'name' => 'Legacy Monitor',
            'url' => 'https://legacy.example.com',
            'interval' => 60,
            'timeout' => 30,
            'expected_status_code' => 200,
            'status' => 'up',
            'user_id' => \App\Models\User::factory()->create()->id,
            'public_token' => \Illuminate\Support\Str::random(32),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseMissing('seo_checks', ['monitor_id' => $monitorId]);

        // Run the backfill migration's up() in isolation.
        $path = glob(database_path('migrations/*_backfill_seo_checks_for_existing_monitors.php'));
        $migration = require $path[0];
        $migration->up();

        $this->assertDatabaseCount('seo_checks', 1);
        $this->assertDatabaseHas('seo_checks', [
            'monitor_id' => $monitorId,
            'interval' => 1440,
            'www_redirect' => SeoCheck::NONE,
            'robots_ok' => false,
        ]);
    }

    public function test_monitor_creation_succeeds_and_logs_when_paired_row_insert_fails(): void
    {
        Log::spy();

        // Remove the seo_checks table so the paired insert throws; the hook
        // must catch, log, and let Monitor creation succeed regardless.
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('seo_check_logs');
        Schema::dropIfExists('seo_checks');
        Schema::enableForeignKeyConstraints();

        $monitor = Monitor::factory()->create();

        $this->assertTrue($monitor->exists);
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id]);
        Log::shouldHaveReceived('error')->once();
    }
}
