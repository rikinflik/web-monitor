<?php

namespace Tests\Feature;

use App\Models\AlertFinding;
use App\Models\AlertSource;
use Database\Seeders\AlertSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * T5c: `alert_sources.security_only` and `alert_findings.is_security` are
 * added by a single additive migration, which also backfills `security_only`
 * for the five security-only sources and `is_security` for every finding
 * that already existed — using AlertSecurityClassifier, not a second copy of
 * its rule (see AlertSecurityClassifierTest for the classification rule
 * itself).
 *
 * RefreshDatabase already runs this migration for every test in the suite
 * (it is one of the migrations that creates/alters the schema), so by the
 * time a test method runs, `security_only` is already correctly seeded and
 * the migration's backfill method has already run once (over an empty
 * `alert_findings` table, since the table is freshly created in every
 * test). To prove the backfill logic itself — not just that the columns
 * exist — this test inserts findings that look like pre-existing data (the
 * state real rows were in before this migration shipped) and re-invokes the
 * migration's own `backfillIsSecurity()` method directly, the same method
 * `up()` calls, rather than duplicating its logic.
 */
#[Group('alert-security-backfill-migration')]
class AlertSecurityBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrationInstance(): object
    {
        return require database_path('migrations/2026_10_22_180000_add_security_flag_to_alert_sources_and_findings.php');
    }

    public function test_seeder_marks_the_five_github_and_security_feed_sources_as_security_only_and_php_as_not(): void
    {
        (new AlertSourceSeeder)->run();

        foreach (['drupal', 'wordpress', 'laravel', 'vue', 'angular'] as $key) {
            $this->assertDatabaseHas('alert_sources', ['key' => $key, 'security_only' => true]);
        }

        $this->assertDatabaseHas('alert_sources', ['key' => 'php', 'security_only' => false]);
    }

    public function test_backfill_flags_a_security_only_source_finding_regardless_of_title(): void
    {
        $wordpress = AlertSource::where('key', 'wordpress')->firstOrFail();

        $finding = AlertFinding::factory()->create([
            'alert_source_id' => $wordpress->id,
            'title' => 'Routine WordPress announcement with no flagging words at all',
        ]);

        $this->migrationInstance()->backfillIsSecurity();

        $this->assertTrue($finding->fresh()->is_security);
    }

    public function test_backfill_flags_the_real_php_critical_advisory_and_not_a_routine_release(): void
    {
        $php = AlertSource::where('key', 'php')->firstOrFail();

        $advisory = AlertFinding::factory()->create([
            'alert_source_id' => $php->id,
            'external_id' => 'https://www.php.net/advisory-2026-10-21',
            'title' => 'Security advisory: Critical remote code execution vulnerabilities, update now',
        ]);

        $release = AlertFinding::factory()->create([
            'alert_source_id' => $php->id,
            'external_id' => 'https://www.php.net/release-8.5.12',
            'title' => 'PHP 8.5.12 Released!',
        ]);

        $this->migrationInstance()->backfillIsSecurity();

        $this->assertTrue($advisory->fresh()->is_security);
        $this->assertFalse($release->fresh()->is_security);
    }

    // -------------------------------------------------------------------------
    // T7b defect 4: up() must be re-runnable after it has already been
    // applied. On MariaDB, DDL auto-commits — if up() died part-way through
    // the row-by-row backfill, the `migrations` table row is never written,
    // yet both columns already exist, and the next `artisan migrate` would
    // die on a duplicate-column error with no re-entry path.
    // -------------------------------------------------------------------------

    /**
     * RefreshDatabase has already run this migration once for every test in
     * this suite, so calling up() again here exercises exactly the
     * already-applied path a re-run after a partial failure would hit.
     */
    public function test_up_is_safely_re_runnable_once_already_applied(): void
    {
        $this->migrationInstance()->up();

        $this->assertTrue(Schema::hasColumn('alert_sources', 'security_only'));
        $this->assertTrue(Schema::hasColumn('alert_findings', 'is_security'));
        $this->assertTrue(Schema::hasIndex('alert_findings', 'alert_findings_source_security_index'));
    }

    /**
     * backfillIsSecurity() recomputes is_security from title on every call
     * (via AlertSecurityClassifier), rather than e.g. only touching rows
     * where the column is still at its default — verify that recomputation
     * is genuinely idempotent before a re-run of up() is allowed to rely on
     * it: running it twice must leave the same findings flagged the same
     * way, not toggle or duplicate anything.
     */
    public function test_backfill_is_idempotent_across_repeated_calls(): void
    {
        $php = AlertSource::where('key', 'php')->firstOrFail();

        $advisory = AlertFinding::factory()->create([
            'alert_source_id' => $php->id,
            'external_id' => 'https://www.php.net/advisory-2026-10-21',
            'title' => 'Security advisory: Critical remote code execution vulnerabilities, update now',
        ]);
        $release = AlertFinding::factory()->create([
            'alert_source_id' => $php->id,
            'external_id' => 'https://www.php.net/release-8.5.12',
            'title' => 'PHP 8.5.12 Released!',
        ]);

        $this->migrationInstance()->backfillIsSecurity();
        $this->migrationInstance()->backfillIsSecurity();

        $this->assertSame(2, AlertFinding::query()->where('alert_source_id', $php->id)->count());
        $this->assertTrue($advisory->fresh()->is_security);
        $this->assertFalse($release->fresh()->is_security);
    }
}
