<?php

use App\Models\AlertSource;
use App\Support\AlertSecurityClassifier;
use Database\Seeders\AlertSourceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive: adds `alert_sources.security_only` and
     * `alert_findings.is_security`, re-runs AlertSourceSeeder (now that the
     * column exists) to set `security_only` correctly per source — the
     * seeder is the single place the six rows and their security_only
     * values are defined, so this migration does not keep a second,
     * separately-maintained list of which keys are security-only — then
     * backfills `is_security` for every finding that already exists (495
     * php / 50 drupal / 10 wordpress / 27 laravel / 1 vue / 20 angular in
     * the live dev DB at the time this shipped).
     *
     * Every schema change is guarded by a hasColumn()/hasIndex() check so
     * up() is safely re-runnable: on MariaDB, DDL auto-commits, so a run
     * that died part-way through the row-by-row backfill below would leave
     * both columns already created but the `migrations` table row never
     * written — the next `artisan migrate` must not then die on a
     * duplicate-column error with no re-entry path. The reseed and the
     * backfill itself are left unconditional: AlertSourceSeeder::run() is
     * documented idempotent (updateOrCreate keyed on `key`), and
     * backfillIsSecurity() recomputes from `title` on every call (see its
     * own docblock) — re-running either on a no-op schema pass is harmless.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('alert_sources', 'security_only')) {
            Schema::table('alert_sources', function (Blueprint $table) {
                $table->boolean('security_only')->default(false)->after('kind');
            });
        }

        if (! Schema::hasColumn('alert_findings', 'is_security')) {
            Schema::table('alert_findings', function (Blueprint $table) {
                $table->boolean('is_security')->default(false)->after('severity');
            });
        }

        // Composite, not a standalone index on is_security alone: the
        // dashboard's per-source cap query (AlertController) partitions its
        // ROW_NUMBER() window by alert_source_id and now also orders by
        // is_security within that partition, so the index needs to serve
        // "rows for this source, is_security-first" together.
        if (! Schema::hasIndex('alert_findings', 'alert_findings_source_security_index')) {
            Schema::table('alert_findings', function (Blueprint $table) {
                $table->index(['alert_source_id', 'is_security'], 'alert_findings_source_security_index');
            });
        }

        (new AlertSourceSeeder)->run();

        $this->backfillIsSecurity();
    }

    /**
     * Reverse the migrations. Schema-only: drops exactly what up() added, in
     * reverse order (index before column, since MySQL refuses to drop a
     * column still covered by an index).
     *
     * NOT behaviourally symmetric, despite being schema-symmetric — an
     * operator reaching for `migrate:rollback` during an incident should
     * know this before running it: the application code shipped alongside
     * this migration hard-references both columns outside of it.
     * `is_security` is read by the `ROW_NUMBER()` window and `orderByDesc()`
     * in App\Support\ConstrainsFindingsPerSource (used by both
     * AlertController and PublicOverviewController), and `security_only` is
     * read by AlertCheckService::insertFinding() on every insert. Rolling
     * this migration back while that code is still deployed turns a
     * classification complaint into 500s on both the dashboard and the
     * public overview page, and a failing insert on every hourly
     * CheckAlertSourceJob run — rolling back the schema requires rolling
     * back (or patching) that code first, not just this file.
     */
    public function down(): void
    {
        Schema::table('alert_findings', function (Blueprint $table) {
            $table->dropIndex('alert_findings_source_security_index');
            $table->dropColumn('is_security');
        });

        Schema::table('alert_sources', function (Blueprint $table) {
            $table->dropColumn('security_only');
        });
    }

    /**
     * Classify every existing finding via AlertSecurityClassifier — the
     * same classifier AlertCheckService calls at insert time, so the
     * backfilled rows and newly inserted rows are decided by identical
     * logic, never a second copy of the rule.
     *
     * Public (not private) so a test can invoke it directly against
     * findings inserted to simulate pre-migration data, without re-running
     * the schema changes in up() (which would fail on an already-added
     * column).
     */
    public function backfillIsSecurity(): void
    {
        AlertSource::query()->each(function (AlertSource $source) {
            $source->findings()->chunkById(500, function ($findings) use ($source) {
                foreach ($findings as $finding) {
                    $finding->is_security = AlertSecurityClassifier::isSecurity($source, $finding->title);
                    $finding->saveQuietly();
                }
            });
        });
    }
};
