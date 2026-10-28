<?php

namespace Tests\Feature;

use App\Models\AlertFinding;
use App\Models\AlertSource;
use Database\Seeders\AlertSourceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alert-source')]
class AlertSourceTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Seeder
    // -------------------------------------------------------------------------

    public function test_seeder_creates_exactly_six_sources_with_expected_key_and_kind(): void
    {
        (new AlertSourceSeeder)->run();

        $this->assertDatabaseCount('alert_sources', 6);

        $expected = [
            'php' => AlertSource::KIND_RSS,
            'drupal' => AlertSource::KIND_RSS,
            'wordpress' => AlertSource::KIND_RSS,
            'laravel' => AlertSource::KIND_GITHUB_ADVISORY,
            'vue' => AlertSource::KIND_GITHUB_ADVISORY,
            'angular' => AlertSource::KIND_GITHUB_ADVISORY,
        ];

        foreach ($expected as $key => $kind) {
            $this->assertDatabaseHas('alert_sources', [
                'key' => $key,
                'kind' => $kind,
            ]);
        }
    }

    public function test_seeded_config_round_trips_as_an_array(): void
    {
        (new AlertSourceSeeder)->run();

        // Every ecosystem's config is pinned exactly: a typo in a feed URL
        // or an npm/composer package list would ship an ecosystem that
        // quietly never produces findings — the hardest bug to notice in a
        // monitoring tool.
        $expectedConfig = [
            'php' => ['url' => 'https://www.php.net/feed.atom'],
            'drupal' => ['url' => 'https://www.drupal.org/security/rss.xml'],
            'wordpress' => ['url' => 'https://wordpress.org/news/category/security/feed/'],
            'laravel' => ['ecosystem' => 'composer', 'affects' => 'laravel/framework'],
            'vue' => ['ecosystem' => 'npm', 'affects' => 'vue,@vue/compiler-sfc'],
            'angular' => [
                'ecosystem' => 'npm',
                'affects' => '@angular/core,@angular/router,@angular/common',
            ],
        ];

        foreach ($expectedConfig as $key => $config) {
            $source = AlertSource::query()->where('key', $key)->firstOrFail();

            $this->assertIsArray($source->config, "config for '{$key}' must round-trip as an array.");
            $this->assertSame($config, $source->config, "config for '{$key}' does not match the seeder's canonical definition.");
        }
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        (new AlertSourceSeeder)->run();
        (new AlertSourceSeeder)->run();

        $this->assertDatabaseCount('alert_sources', 6);
    }

    public function test_reseed_refreshes_drifted_config_without_resetting_last_checked_at_or_dropping_findings(): void
    {
        // Migration already ran the seeder once (see the migrate-alone test
        // above); run it again explicitly so this test is self-contained.
        (new AlertSourceSeeder)->run();

        $php = AlertSource::query()->where('key', 'php')->firstOrFail();
        $checkedAt = now()->subHour()->startOfSecond();
        $php->update([
            'config' => ['url' => 'https://stale.example.com/feed.atom'],
            'last_checked_at' => $checkedAt,
        ]);

        $finding = AlertFinding::factory()->for($php)->create();

        (new AlertSourceSeeder)->run();

        $php->refresh();

        // A moved feed URL must be picked up by a re-seed (updateOrCreate,
        // not firstOrCreate, which would never refresh an existing row).
        $this->assertSame(
            'https://www.php.net/feed.atom',
            $php->config['url'],
            'A re-seed must refresh config that drifted from the canonical definition.'
        );

        // last_checked_at is check-time data, not seed data; a re-seed must
        // not touch it even though it is absent from the seeder's row array.
        $this->assertTrue(
            $php->last_checked_at->equalTo($checkedAt),
            'A re-seed must not reset last_checked_at.'
        );

        // A delete()+create() re-seed would cascade-delete this finding.
        $this->assertDatabaseHas('alert_findings', ['id' => $finding->id]);
        $this->assertDatabaseCount('alert_findings', 1);
    }

    public function test_migrate_alone_installs_the_six_sources_without_seeding(): void
    {
        // RefreshDatabase has already run migrations for this test (no
        // --seed involved). Installing the six sources must not depend on
        // DatabaseSeeder, which provisions a known-credential admin.
        $this->assertDatabaseCount('alert_sources', 6);
    }

    // -------------------------------------------------------------------------
    // Staleness (T7e) — distinguishes "checked recently" from "the pipeline
    // is dead". The schedule is hourly (routes/console.php:
    // Schedule::command('alerts:check')->hourly()), so
    // AlertSource::STALE_AFTER_HOURS = 3 gives roughly three missed runs of
    // slack before a quiet source is flagged — enough to absorb a single
    // delayed/overlapping run without false-alarming, while still catching a
    // genuinely dead queue worker or cron well before "Healthy" has been
    // silently wrong for days.
    // -------------------------------------------------------------------------

    public function test_is_stale_returns_false_for_a_source_checked_within_the_threshold(): void
    {
        $source = AlertSource::factory()
            ->checkedAt(now()->subHours(AlertSource::STALE_AFTER_HOURS)->addMinute())
            ->create();

        $this->assertFalse($source->isStale());
    }

    public function test_is_stale_returns_true_for_a_source_checked_before_the_threshold(): void
    {
        $source = AlertSource::factory()
            ->checkedAt(now()->subHours(AlertSource::STALE_AFTER_HOURS)->subMinute())
            ->create();

        $this->assertTrue($source->isStale());
    }

    /**
     * Sabotage-proof the exact boundary: one second inside the threshold
     * must not be stale, one second past it must be — pins the ">" vs ">="
     * choice rather than just "somewhere around 3 hours".
     */
    public function test_is_stale_boundary_is_exclusive_of_the_threshold_itself(): void
    {
        $justInside = AlertSource::factory()
            ->checkedAt(now()->subHours(AlertSource::STALE_AFTER_HOURS)->addSecond())
            ->create();
        $justOutside = AlertSource::factory()
            ->checkedAt(now()->subHours(AlertSource::STALE_AFTER_HOURS)->subSecond())
            ->create();

        $this->assertFalse($justInside->isStale(), 'A source checked one second inside the threshold must not be stale.');
        $this->assertTrue($justOutside->isStale(), 'A source checked one second past the threshold must be stale.');
    }

    public function test_is_stale_returns_false_for_a_source_that_has_never_been_checked(): void
    {
        $source = AlertSource::factory()->checkedAt(null)->create();

        $this->assertFalse($source->isStale(), 'A never-checked source is "Never checked", not "Stale" — isStale() must not conflate the two.');
    }

    // -------------------------------------------------------------------------
    // healthBadge() — precedence between Check failing / Stale / Healthy /
    // Never checked, the single place both Blade views derive their status
    // pill from (mirrors AlertFinding::severityBadge()'s role for severity).
    // -------------------------------------------------------------------------

    public function test_health_badge_is_never_checked_when_last_checked_at_is_null(): void
    {
        $source = AlertSource::factory()->checkedAt(null)->create();

        $this->assertSame('Never checked', $source->healthBadge()['label']);
    }

    public function test_health_badge_is_healthy_when_checked_recently_with_no_error(): void
    {
        $source = AlertSource::factory()->checkedAt(now())->create();

        $this->assertSame('Healthy', $source->healthBadge()['label']);
    }

    public function test_health_badge_is_stale_when_checked_before_the_threshold_with_no_error(): void
    {
        $source = AlertSource::factory()
            ->checkedAt(now()->subHours(AlertSource::STALE_AFTER_HOURS)->subMinute())
            ->create();

        $this->assertSame('Stale', $source->healthBadge()['label']);
    }

    /**
     * Precedence: a source that is both stale AND failing must render
     * "Check failing", not "Stale" — a concrete, known failure reason
     * outranks mere staleness, and showing both would be incoherent (which
     * one is "the" status?). last_error already implies a check WAS
     * attempted (however long ago), so this is the more informative of the
     * two truths.
     */
    public function test_health_badge_prefers_check_failing_over_stale_when_both_apply(): void
    {
        $source = AlertSource::factory()
            ->checkedAt(now()->subHours(AlertSource::STALE_AFTER_HOURS)->subDay())
            ->create(['last_error' => 'Connection timed out after 10s']);

        $this->assertSame('Check failing', $source->healthBadge()['label']);
    }

    /**
     * Precedence: last_error must win over a recent (non-stale) check too —
     * the existing contract AlertControllerTest already pins at the Blade
     * level, re-asserted here at the model level since healthBadge() is now
     * the single source of truth both views render from.
     */
    public function test_health_badge_prefers_check_failing_over_healthy(): void
    {
        $source = AlertSource::factory()
            ->checkedAt(now())
            ->create(['last_error' => 'HTTP 403 from wordpress.org']);

        $this->assertSame('Check failing', $source->healthBadge()['label']);
    }

    public function test_alert_source_tracks_last_success_and_last_error_independently_of_last_checked(): void
    {
        $source = AlertSource::factory()->create();

        $this->assertNull($source->last_success_at);
        $this->assertNull($source->last_error);

        $checkedAt = now();
        $source->update([
            'last_checked_at' => $checkedAt,
            'last_error' => 'Connection timed out after 10s',
        ]);
        $source->refresh();

        $this->assertInstanceOf(Carbon::class, $source->last_checked_at);
        $this->assertSame('Connection timed out after 10s', $source->last_error);
        $this->assertNull($source->last_success_at, 'last_success_at must stay null when the most recent attempt failed.');

        $successAt = now();
        $source->update([
            'last_success_at' => $successAt,
            'last_error' => null,
        ]);
        $source->refresh();

        $this->assertInstanceOf(Carbon::class, $source->last_success_at);
        $this->assertNull($source->last_error, 'last_error must be cleared once an attempt succeeds.');
    }

    // -------------------------------------------------------------------------
    // Findings dedup + relations
    // -------------------------------------------------------------------------

    public function test_duplicate_external_id_under_the_same_source_is_rejected_by_the_unique_constraint(): void
    {
        $source = AlertSource::factory()->create();

        $original = AlertFinding::factory()->for($source)->create([
            'external_id' => 'GHSA-aaaa-bbbb',
            'title' => 'Original title',
        ]);

        try {
            AlertFinding::factory()->for($source)->create(['external_id' => 'GHSA-aaaa-bbbb']);
            $this->fail('Expected a unique constraint violation, none was thrown.');
        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation, on both
            // SQLite and MySQL. Any statement failure (e.g. a missing
            // column) would also throw QueryException, so pin the SQLSTATE
            // and message specifically to the unique-constraint case.
            $this->assertSame('23000', $e->getCode(), 'Expected an integrity constraint violation, not an unrelated query failure.');
            $this->assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }

        $original->refresh();
        $this->assertSame('GHSA-aaaa-bbbb', $original->external_id);
        $this->assertSame('Original title', $original->title);
        $this->assertDatabaseCount('alert_findings', 1);
    }

    public function test_same_external_id_under_two_different_sources_is_allowed(): void
    {
        $sourceA = AlertSource::factory()->create();
        $sourceB = AlertSource::factory()->create();

        AlertFinding::factory()->for($sourceA)->create(['external_id' => 'shared-guid']);
        AlertFinding::factory()->for($sourceB)->create(['external_id' => 'shared-guid']);

        $this->assertDatabaseCount('alert_findings', 2);
    }

    public function test_date_casts_return_carbon_instances(): void
    {
        $source = AlertSource::factory()->checkedAt(now())->create();
        $finding = AlertFinding::factory()->for($source)->create();

        // Reload from the database: an in-memory, freshly-created model
        // still holds the Carbon instance it was given at construction time
        // regardless of $casts, so asserting against it would prove nothing
        // about the cast. Only a value rehydrated from the raw DB string
        // exercises the cast.
        $source = $source->fresh();
        $finding = $finding->fresh();

        $this->assertInstanceOf(Carbon::class, $source->last_checked_at);
        $this->assertInstanceOf(Carbon::class, $finding->published_at);
        $this->assertInstanceOf(Carbon::class, $finding->first_seen_at);
    }

    public function test_relations_traverse_between_source_and_findings(): void
    {
        $source = AlertSource::factory()->create();
        $finding = AlertFinding::factory()->for($source)->create();

        $this->assertTrue($source->findings->contains('id', $finding->id));
        $this->assertSame($source->id, $finding->alertSource->id);
        $this->assertSame($source->key, $finding->alertSource->key);
    }

    public function test_deleting_a_source_cascades_to_its_findings(): void
    {
        $source = AlertSource::factory()->create();
        AlertFinding::factory()->for($source)->create();

        $this->assertDatabaseCount('alert_findings', 1);

        $source->delete();

        $this->assertDatabaseCount('alert_findings', 0);
    }

    public function test_empty_external_id_is_rejected_at_the_model_level(): void
    {
        $source = AlertSource::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_id must not be empty');

        AlertFinding::factory()->for($source)->create(['external_id' => '']);
    }

    public function test_whitespace_only_external_id_is_rejected(): void
    {
        $source = AlertSource::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        AlertFinding::factory()->for($source)->create(['external_id' => "  \t  "]);
    }

    // -------------------------------------------------------------------------
    // Column widths (declared lengths)
    //
    // SQLite's grammar discards declared string lengths entirely (it always
    // writes bare `varchar`, regardless of what's passed to `string()`), so
    // PRAGMA/Schema::getColumns() on the test connection cannot distinguish
    // a 255-char column from a 2048-char one. The only way to pin the
    // *declared* length that ships to production is to compile the actual
    // migration against the real MySQL grammar in `pretend()` mode, which
    // never opens a PDO connection — it only compiles and logs the SQL.
    // -------------------------------------------------------------------------

    public function test_finding_text_columns_are_declared_wide_enough_for_production_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_22_165705_create_alert_findings_table.php');

        $originalDefault = config('database.default');
        config(['database.default' => 'mysql']);

        try {
            $queries = DB::connection('mysql')->pretend(fn () => $migration->up());
        } finally {
            config(['database.default' => $originalDefault]);
        }

        $createTableSql = collect($queries)
            ->pluck('query')
            ->first(fn (string $sql) => str_contains($sql, 'create table') && str_contains($sql, 'alert_findings'));

        $this->assertNotNull($createTableSql, 'Expected the alert_findings CREATE TABLE statement to be captured.');
        $this->assertStringContainsString('`external_id` varchar(512)', $createTableSql, 'external_id must stay at 512 to respect the InnoDB composite-key limit under utf8mb4.');
        $this->assertStringContainsString('`title` varchar(1024)', $createTableSql, 'title must be wide enough for GitHub advisory summaries (up to 1024 chars).');
        $this->assertStringContainsString('`url` varchar(2048)', $createTableSql, 'url must be wide enough for arbitrary-length feed links.');
    }

    public function test_external_id_dedup_collation_is_pinned_case_sensitive_on_production_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_22_165705_create_alert_findings_table.php');

        $originalDefault = config('database.default');
        config(['database.default' => 'mysql']);

        try {
            $queries = DB::connection('mysql')->pretend(fn () => $migration->up());
        } finally {
            config(['database.default' => $originalDefault]);
        }

        $createTableSql = collect($queries)
            ->pluck('query')
            ->first(fn (string $sql) => str_contains($sql, 'create table') && str_contains($sql, 'alert_findings'));

        $this->assertNotNull($createTableSql);
        // external_id is a machine identifier dedup key; it must not inherit
        // production's case-insensitive connection default (utf8mb4_unicode_ci).
        $this->assertStringContainsString(
            "`external_id` varchar(512) collate 'utf8mb4_bin' not null",
            $createTableSql,
            'external_id dedup must be pinned case-sensitive, not inherited from the connection default collation.'
        );
    }
}
