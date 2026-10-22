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
     */
    public function up(): void
    {
        Schema::table('alert_sources', function (Blueprint $table) {
            $table->boolean('security_only')->default(false)->after('kind');
        });

        Schema::table('alert_findings', function (Blueprint $table) {
            $table->boolean('is_security')->default(false)->after('severity');
        });

        // Composite, not a standalone index on is_security alone: the
        // dashboard's per-source cap query (AlertController) partitions its
        // ROW_NUMBER() window by alert_source_id and now also orders by
        // is_security within that partition, so the index needs to serve
        // "rows for this source, is_security-first" together.
        Schema::table('alert_findings', function (Blueprint $table) {
            $table->index(['alert_source_id', 'is_security'], 'alert_findings_source_security_index');
        });

        (new AlertSourceSeeder)->run();

        $this->backfillIsSecurity();
    }

    /**
     * Reverse the migrations. Additive and symmetric: drops exactly what
     * up() added, in reverse order (index before column, since MySQL
     * refuses to drop a column still covered by an index).
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
